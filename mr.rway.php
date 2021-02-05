<?php
include 'Config.php';
  error_reporting(E_ERROR);


  /* Mongo */
  $manager = new MongoDB\Driver\Manager('mongodb://'. Config::MON_USER . ':' . Config::MON_PWD . '@' . Config::MON_HOST);
  $bulk = new MongoDB\Driver\BulkWrite(['ordered' => true]);
  $bulk2 = new MongoDB\Driver\BulkWrite(['ordered' => true]);
  $wc = new MongoDB\Driver\WriteConcern(MongoDB\Driver\WriteConcern::MAJORITY, 1000);
  
  /* Oracle */
  /*
  $conn = oci_connect(Config::ORA_USER,Config::ORA_PWD,Config::ORA_TNS,'AL32UTF8');
  if (!$conn) {
	$e = oci_error();
    trigger_error(htmlentities($e['message'], ENT_QUOTES), E_USER_ERROR);
  }
  */

  function pop3_login($host,$port,$user,$pass,$folder="INBOX",$ssl=false)
  {
    $ssl=($ssl==false)?"/novalidate-cert":"";
    return (imap_open("{"."$host:$port/pop3$ssl"."}$folder",$user,$pass));
  }

  function pop3_stat($connection)
  {
      $check = imap_mailboxmsginfo($connection);
      return ((array)$check);
  }

  function mime_dec($in){
    $x = imap_mime_header_decode($in);
    $text = '';
    foreach ($x as $part){
      if($part->charset == 'default') { $part->charset = 'UTF-8'; }
	  $text .= mb_convert_encoding($part->text,'UTF-8', $part->charset);
    }
    return $text;
  }

  /*
  *   Обработка сообщения:
  *   - Ищем вложения
  *   - Возвращает массив со статусом и всеми CSV вложениями (если обнаружены)
  */
  function process_message($connection, $message_number){
    $att_index = 0;
    $structure = imap_fetchstructure($connection, $message_number);
    foreach ($structure->parts as $index => $part){
      /* Если раздел содержит аттачмент и оно имеет тип CSV */
      echo "...sub type:" . strtolower($part->subtype) . "\n";
      if($part->ifdparameters && strtolower($part->subtype) == 'octet-stream'){
        foreach($part->dparameters as $object){
          if(strtolower($object->attribute) == 'filename') {
    					$attachments[$att_index]['filename'] = mime_dec($object->value);
              $attachments[$att_index]['encoding'] = $part->encoding;
              echo "...filename:" . mime_dec($object->value) . "\n";
              echo "...encoding:" . $part->encoding . "\n";
              //странно, но сам аттачмент в следующем (i+1) разделе
              $file = imap_fetchbody($connection,$message_number,$index+1);
              //echo "размер до декодирования " . strlen($file) . "\n";
              if($part->encoding == 3){
                $attachments[$att_index]['body'] = imap_base64($file);
              //  file_put_contents('/tmp/' . $attachment['filename'], imap_base64($file));
              } elseif ($part->encoding == 1){
                $attachments[$att_index]['body'] = $file;
              }
              $att_index++;
    			}
        }
      } else {
        $ret['code'] = false;
      }
    }
    $ret['attachments'] = $attachments;
    return $ret;
  }
  /* ------------------------------------------- */




  /* 
    Обработка вложения
  */
  function process_attachments($attachment, $message_id){
    try {
        file_put_contents('/tmp/' . $attachment['filename'], $attachment['body']);
		$command = "scp /tmp/" . $attachment['filename'] . " oracle@reqora:/oracle/soft/user_projects/wagons/csv_files/" . $attachment['filename'];
		exec($command,$stdout,$rc);
		if($rc == 0){
			echo "...file remote copied \n";
		} else {
			echo "...error copying to ReqOra \n";
		}
		/*
		$sql = "INSERT INTO doc_files (file_name, mime_type, file_size, status, err, blob_body, content)
				VALUES
				(:file_name, 'application/vnd.ms-excel', :file_size, 'ok', '', v_blob_body, 'main');";
		$op = oci_parse($conn, $sql);
		oci_bind_by_name($op,":file_name", $attachment['filename'], SQLT_CHR);
		oci_bind_by_name($op,":file_size", strlen($attachment['body']), SQLT_INT);
		oci_execute($op);
		*/
    } catch (\Exception $e) {
        $ret = false;
        return false;
        exit();
    }
    
    return true;
  }
  /* -------------------------------------------- */

  // соединяемся с Mailbox
  $connection = pop3_login(Config::MSG_HOST, Config::MSG_PORT, Config::MSG_USER, Config::MSG_PASS);
  $status = imap_check($connection);
  $now = new DateTime();
  echo "===>" . $now->format('Y-m-d H:i') . "(GMT) ===\n";
  echo "  Connecting to: " . $status->Mailbox . "\n";

  // массив всех сообщений
  $all_messages = imap_fetch_overview($connection, '1:' . $status->Nmsgs);
  foreach ($all_messages as $msg) {
      $index = $msg->msgno;
      $messages[$index]['_id'] = trim($msg->message_id, '<>');
      $messages[$index]['date'] = $msg->date;
      $messages[$index]['from'] = filter_var(mime_dec($msg->from), FILTER_SANITIZE_EMAIL);
  }

  $process = false;
  foreach ($messages as $index => $msg){
    
    $query = new MongoDB\Driver\Query(["_id" =>  $msg['_id']]);
    $cursor = $manager->executeQuery('MailRobots.mr.vagons', $query)->toArray();
    // Если письмо ранее не обрабатывалось
    if ( count($cursor) == 0){

	    $process = true;
      echo "  Обрабатываем новое сообщение id=" . $msg['_id'] . "\n";
    
      /* обрабатываем сообщение - ищем CSV вложения  */
      $result = process_message($connection, $index);
      /* Если в результате обработки обнаружены CSV вложения */
      if (count($result['attachments'])){
         foreach($result['attachments'] as $attachment){
           $status = process_attachments($attachment, $msg['_id']);
           if ($status) {
              $msg['status']['code'] = '0';
              $msg['status']['text'] = 'Обнаружен CSV';
           } 
         }
      } else {
        $msg['status']['code'] = '2';
        $msg['status']['text'] = 'Вложение не обнаружено';
      }
	  $bulk->insert($msg);
    } else {
      echo "  Сообщение id=" . $msg['_id'] . " уже обрабатывалось.\n";
    }
  }
  /* GUID обработанных сообщений сохраняем в БД*/
  if($process){
    $result = $manager->executeBulkWrite('MailRobots.mr.vagons', $bulk, $wc);
  }
