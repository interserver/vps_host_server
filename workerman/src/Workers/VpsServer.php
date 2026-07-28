<?php
use MyAdmin\VpsHost\Agent;
use Workerman\Worker;

$vps_worker = new Worker();
$vps_worker->name = 'VpsHostWorker';
$vps_worker->onWorkerStart = function (Worker $worker) {
	global $events;
	$events = new Agent();
	$events->onWorkerStart($worker);
};

// If not in the root directory, run runAll method
if (!defined('GLOBAL_START')) {
	Worker::runAll();
}
