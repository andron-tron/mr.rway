<?php
require __DIR__ . '/vendor/autoload.php';
include 'Config.php';
  error_reporting(E_ERROR);


  /* Mongo DB for logging*/
  $manager = new MongoDB\Driver\Manager('mongodb://'. Config::MON_USER . ':' . Config::MON_PWD . '@' . Config::MON_HOST);
  $bulk = new MongoDB\Driver\BulkWrite(['ordered' => true]);
  $bulk2 = new MongoDB\Driver\BulkWrite(['ordered' => true]);
  $wc = new MongoDB\Driver\WriteConcern(MongoDB\Driver\WriteConcern::MAJORITY, 1000);
  
  
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
      echo "..... sub type:" . strtolower($part->subtype) . "\n";
      if($part->ifdparameters && (strtolower($part->subtype) == 'octet-stream' || strtolower($part->subtype) == 'vnd.ms-excel')){
        foreach($part->dparameters as $object){
          if(strtolower($object->attribute) == 'filename') {
    					$attachments[$att_index]['filename'] = mime_dec($object->value);
              $attachments[$att_index]['encoding'] = $part->encoding;
              echo "..... filename:" . mime_dec($object->value) . "\n";
              echo "..... encoding:" . $part->encoding . "\n";
              //странно, но сам аттачмент в следующем (i+1) разделе
              $file = imap_fetchbody($connection,$message_number,$index+1);
              
              if($part->encoding == 3){
                $attachments[$att_index]['body'] = imap_base64($file);
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
    -- удаленное копирование
    -- проверка
  */
  function process_attachments($attachment, $message_id){
    try {
      file_put_contents('/tmp/' . $attachment['filename'], $attachment['body']);
      // file_put_contents('d:/tmp/' . $attachment['filename'], $attachment['body']);
      $command = 'scp /tmp/' . $attachment['filename'] . " oracle@reqora:/oracle/soft/user_projects/wagons/csv_files/" . $attachment['filename'];
		  exec($command,$stdout,$rc);
      
		  if($rc == 0){
			  echo "..... file remote copied \n";
		  } else {
			  echo "..... error copying to ReqOra \n";
		  }
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
  echo "\n\n===>" . $now->format('Y-m-d H:i') . "(GMT) ===\n";
  echo "... Connecting to: " . $status->Mailbox . "\n";

  // массив всех сообщений
  $all_messages = imap_fetch_overview($connection, '1:' . $status->Nmsgs);
  foreach ($all_messages as $msg) {
      $from = filter_var(mime_dec($msg->from), FILTER_SANITIZE_EMAIL);
      // только сообщения от нужного автора
      if($from == Config::MSG_FROM){
        $index = $msg->msgno;
        $messages[$index]['_id'] = trim($msg->message_id, '<>');
        $mail_date = DateTime::createFromFormat('D, d M Y H:i:s P',$msg->date);
        $json_date = New MongoDB\BSON\UTCDateTime($pdate );
        $messages[$index]['date'] = $json_date;
        $messages[$index]['from'] = $from;
      }
  }

  $process = false;
  foreach ($messages as $index => $msg){   
    $query = new MongoDB\Driver\Query(["_id" =>  $msg['_id']]);
    $cursor = $manager->executeQuery('MailRobots.mr.vagons', $query)->toArray();
    // Если письмо ранее не обрабатывалось
    if ( count($cursor) == 0){
	    $process = true;
      echo "... Обрабатываем новое сообщение id=" . $msg['_id'] . "\n";
      /* обрабатываем сообщение - ищем CSV вложения  */
      $result = process_message($connection, $index);
      /* Если в результате обработки обнаружены CSV вложения */
      if (count($result['attachments'])){
         foreach($result['attachments'] as $attachment){
           $status = process_attachments($attachment, $msg['_id']);
           if ($status) {
              $msg['status']['code'] = '0';
              $msg['status']['text'] = 'Вложение обработано';
              // delete message in inbox
              imap_delete($connection, $index); 
              imap_expunge($connection); 
           } 
         }
      } else {
        $msg['status']['code'] = '2';
        $msg['status']['text'] = 'Вложение не обнаружено';
      }
	  $bulk->insert($msg);
    } else {
      echo "... Сообщение id=" . $msg['_id'] . " уже обрабатывалось.\n";
    }
  }
  /* GUID обработанных сообщений сохраняем в БД*/
  if($process){
    $result = $manager->executeBulkWrite('MailRobots.mr.vagons', $bulk, $wc);
    
  }
