<?php
use Workerman\Lib\Timer;
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

/**
* onConnect event for websocket connection to hub
*/
return function ($stdObject, AsyncTcpConnection $conn) {
	/** sends a login request to the hub **/
	$json = array(
		'type' => 'login',
		'name' => $stdObject->hostname,
		'module' => 'vps',
		'room_id' => 1,
		'ima' => 'host',
	);
	$stdObject->conn = $conn;
	$stdObject->conn->send(json_encode($json));
};
