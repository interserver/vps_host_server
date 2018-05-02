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
	$task_connection->onMessage = function($task_connection, $task_result) use ($task_connection, $orig_params) {
		//var_dump($task_result);
		$stdObject->conn->send(json_encode(array(
			'type' => 'phpsysinfo_out',
			'for' => $for,
			'params' => $orig_params,
			'content' => json_decode($task_result, true),
		)));
		$task_connection->close();
	};
	$task_connection->connect();
};
