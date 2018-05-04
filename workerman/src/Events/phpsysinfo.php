<?php
use Workerman\Connection\AsyncTcpConnection;

return function($stdObject, $for, $params) {
	global $global, $settings;
	$orig_params = $params;
	$params['json'] = '';
	$args = escapeshellarg(json_encode($params));
	$cmd = 'php /root/cpaneldirect/workerman/phpsysinfo.php '.$args;
	$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
	$task_connection->send(json_encode(array('function' => 'run', 'args' => array('cmd' => $cmd))));
	$conn = $stdObject->conn;
	$task_connection->onMessage = function($task_connection, $task_result) use ($conn, $orig_params) {
		//var_dump($task_result);
		$task_connection->close();
		$conn->send(json_encode(array(
			'type' => 'phpsysinfo',
			'for' => $for,
			'params' => $orig_params,
			'data' => json_decode($task_result, true),
		)));
	};
	$task_connection->connect();
};
