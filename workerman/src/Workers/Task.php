<?php
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

globa $settings;
$task_worker = new Worker('Text://127.0.0.1:55552');
$task_worker->count = 5;
$task_worker->name = 'TaskWorker';
$task_worker->onWorkerStart = function($worker) {
	global $global, $settings;
	$global = new \GlobalData\Client('127.0.0.1:55553');
};
$task_worker->onMessage = function($connection, $task_data) {
	$task_data = json_decode($task_data, true);
	if (isset($task_data['function'])) {
		echo "Starting Task {$task_data['function']}\n";
		$return = isset($task_data['args']) ? call_user_func($task_data['function'], $task_data['args']) : call_user_func($task_data['function']);
		echo "Ending Task {$task_data['function']}\n";
		$connection->send(json_encode($return));
	}
};

// If not in the root directory, run runAll method
if(!defined('GLOBAL_START'))
	Worker::runAll();
