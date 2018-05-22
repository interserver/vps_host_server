<?php
use Workerman\Connection\AsyncTcpConnection;

return function($stdObject) {
	global $global, $settings;
	$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
	$task_connection->send(json_encode(array('function' => 'vps_get_cpu', 'args' => array('type' => $stdObject->type))));
	$conn = $stdObject->conn;
	$task_connection->onMessage = function($task_connection, $task_result) use ($conn) {
		//var_dump($task_result);
		$task_connection->close();
		$conn->send($task_result);
	};
	$task_connection->connect();
};
