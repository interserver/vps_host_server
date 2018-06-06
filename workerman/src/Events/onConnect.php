<?php
use Workerman\Lib\Timer;
use Workerman\Worker;

return function($stdObject, $conn) {
	$stdObject->conn = $conn;
	$json = array(
		'type' => 'login',
		'name' => $stdObject->hostname,
		'module' => 'vps',
		'room_id' => 1,
		'ima' => 'host',
	);
	$conn->send(json_encode($json));
};
