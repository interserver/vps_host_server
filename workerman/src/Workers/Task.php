<?php
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

global $settings;
$task_worker = new Worker('Text://'.$settings['servers']['task']['ip'].':'.$settings['servers']['task']['port']);
$task_worker->count = $settings['servers']['task']['count'];
$task_worker->name = 'TaskWorker';
$task_worker->onWorkerStart = function($worker) {
	global $global, $settings;
	$global = new \GlobalData\Client($settings['servers']['globaldata']['ip'].':'.$settings['servers']['globaldata']['port']);
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