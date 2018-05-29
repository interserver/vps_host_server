<?php
use Workerman\Lib\Timer;

return function($stdObject, $maps) {
	$stdObject->conn = $conn;
    
    $stdObject->vps_get_list();
};
