<?php
use MyAdmin\VpsHost\TaskRegistry;
use Workerman\Worker;

$mytasks = null;
$task_worker = new Worker('Text://127.0.0.1:55552');
$task_worker->count = 2;
$task_worker->name = 'TaskWorker';
$task_worker->onWorkerStart = function ($worker) use (&$mytasks) {
	/**
	* @var \GlobalData\Client
	*/
	global $global;
	$global = new \GlobalData\Client('127.0.0.1:55553');
	$mytasks = new TaskRegistry();
	$mytasks->load(__DIR__.'/../Tasks');
};
$task_worker->onMessage = function ($connection, $task_data) use (&$mytasks) {
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
		$return = isset($task_data['args']) ? $mytasks->call($task_data['type'], $task_data['args']) : $mytasks->call($task_data['type']);
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
