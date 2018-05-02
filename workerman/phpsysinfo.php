<?php
$get = $_SERVER['argv'][1];
$get = json_decode($get, true);
foreach ($get as $field => $value)
	$_GET[$field] = $value;
include 'vendor/phpsysinfo/phpsysinfo/xml.php';
