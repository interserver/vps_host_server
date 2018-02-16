<?php
use Workerman\Worker;

include_once __DIR__.'/../Events.php';

$worker = new Worker();
$worker->name = 'VpsServer';
$worker->onWorkerStart = function($worker) {
	global $events;
	$events = new Events();
	$events->onWorkerStart($worker);
};

// If not in the root directory, run runAll method
if(!defined('GLOBAL_START'))
	Worker::runAll();