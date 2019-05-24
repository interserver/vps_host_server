<?php
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

include_once __DIR__.'/../stdObject.php';

$task_worker = new Worker('Text://127.0.0.1:55552');
$task_worker->count = 2;
$task_worker->name = 'TaskWorker';
$task_worker->onWorkerStart = function ($worker) use (&$task_worker) {
	/**
	* @var \GlobalData\Client
	*/
	global $global;
	$global = new \GlobalData\Client('127.0.0.1:55553');
	$task_worker->mytasks = new stdObject();
	foreach (glob(__DIR__.'/../Tasks/*.php') as $function_file) {
		$function = basename($function_file, '.php');
		$task_worker->mytasks->{$function} = include $function_file;
	}
};
$task_worker->onMessage = function ($connection, $task_data) use (&$task_worker) {
    /**
    * @var \GlobalData\Client
    */
    global $global;
	$task_data = json_decode($task_data, true);
	if (isset($task_data['type'])) {
        if (isset($task_data['lock']) && $task_data['lock'] == true) {
            Worker::safeEcho("Getting Lock for {$task_data['type']}\n");
            do {                
            } while (!$global->cas('busy', 0, 1));
            Worker::safeEcho("Got Lock for {$task_data['type']}\n");
        }
		//Worker::safeEcho("Starting Task {$task_data['type']}\n");
		$return = isset($task_data['args']) ? call_user_func([$task_worker->mytasks, $task_data['type']], $task_data['args']) : call_user_func([$task_worker->mytasks, $task_data['type']]);
		//Worker::safeEcho("Ending Task {$task_data['type']}\n");
		$connection->send(json_encode($return));
        if (isset($task_data['lock']) && $task_data['lock'] == true) {
            Worker::safeEcho("Freeing Lock for {$task_data['type']}\n");
            do {
            } while (!$global->cas('busy', 1, 0));
            Worker::safeEcho("Freed Lock for {$task_data['type']}\n");
        }
	}
};

// If not in the root directory, run runAll method
if (!defined('GLOBAL_START')) {
	Worker::runAll();
}
