<?php
use Workerman\Connection\AsyncTcpConnection;

return function($stdObject) {
	global $global, $settings;
	$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
	$task_connection->send(json_encode(array('function' => 'async_hyperv_get_list', 'args' => array())));
	$task_connection->onMessage = function($task_connection, $task_result) use ($task_connection) {
		 //var_dump($task_result);
		 $task_connection->close();
	};
	$task_connection->connect();
};
