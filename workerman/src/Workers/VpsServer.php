<?php
use Workerman\Worker;

include_once __DIR__.'/../Events.php';

$worker = new Worker();
$worker->name = 'VpsServer';
$worker->onWorkerStart = function($worker) {
	global $events;
	$functions = new stdObject();
	foreach(glob(__DIR__.'/../Functions/*.php') as $function_file) {
		$function = basename($function_file, '.php');
		$functions->{$func} = include $function_file;
	}
	$events = new Events();
	$events->onWorkerStart($worker);
};

// If not in the root directory, run runAll method
if(!defined('GLOBAL_START'))
	Worker::runAll();