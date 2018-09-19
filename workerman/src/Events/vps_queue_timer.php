<?php
use Workerman\Connection\AsyncTcpConnection;

return function ($stdObject) {
	/**
	* @var \GlobalData\Client
	*/
	global $global;
	$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552'); // Asynchronous link with the remote task service
	$task_connection->send(json_encode(array('type' => 'vps_queue', 'args' => $global->settings['vps_queue']['cmds']))); // send data
	$conn = $stdObject->conn;
	$task_connection->onMessage = function (\Workerman\Connection\TcpConnection $task_connection, $task_result) use ($conn) {
		//var_dump($task_result);
		$task_connection->close();
		//$conn->send($task_result);
	};
	$task_connection->connect(); // execute async link
};
