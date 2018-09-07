<?php
use Workerman\Connection\AsyncTcpConnection;

return function ($stdObject, $data) {
	global $global;
	$orig_params = $data['params'];
	$data['params']['json'] = '';
	$args = escapeshellarg(json_encode($data['params']));
	$cmd = 'php /root/cpaneldirect/workerman/phpsysinfo.php '.$args;
	$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
	$task_connection->send(json_encode(array('type' => 'run', 'args' => array('cmd' => $cmd))));
	$conn = $stdObject->conn;
	$task_connection->onMessage = function ($task_connection, $task_result) use ($conn, $data, $orig_params) {
		$task_result = json_decode($task_result);
		if (!is_array($task_result)) {
			$task_result = json_decode($task_result);
		}
		//var_dump($task_result);
		$task_connection->close();
		$data['params'] = $orig_params;
		$data['data'] = base64_encode(gzcompress(json_encode($task_result), 9));
		$conn->send(json_encode($data));
	};
	$task_connection->connect();
};
