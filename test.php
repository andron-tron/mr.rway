<?php
require __DIR__ . '\vendor\autoload.php';

$pdate = DateTime::createFromFormat('D, d M Y H:i:s P' ,"Mon, 1 Feb 2021 10:31:49 +0300");
                                    //Mon, 1 Feb 2021 10:31:49 +0300
var_dump($pdate);
$jd = New MongoDB\BSON\UTCDateTime($pdate );
var_dump($jd);
var_dump($jd->toDateTime()->format('r'));