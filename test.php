<?php
require __DIR__ . '/vendor/autoload.php';
$ptime = strtotime("Fri Feb  5 11:26:58 PM +03 2021");
$pdate = DateTime::createFromFormat('D M d H:i:s A P Y' ,"Fri Feb 5 11:26:58 PM +03 2021");
var_dump($pdate);
$jd = New MongoDB\BSON\UTCDateTime($pdate );
var_dump($jd);
var_dump($jd->toDateTime()->format('r'));