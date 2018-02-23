<?php
use Workerman\Worker;

include_once __DIR__.'/../stdObject.php';

$vps_worker = new Worker();
$vps_worker->name = 'VpsServer';
$vps_worker->onWorkerStart = function($worker) {
	global $events;
	$events = new stdObject();
	foreach(glob(__DIR__.'/../Events/*.php') as $function_file) {
		$function = basename($function_file, '.php');
		$events->{$function} = include $function_file;
	}
	$events->onWorkerStart($worker);
};

// If not in the root directory, run runAll method
if(!defined('GLOBAL_START'))
	Worker::runAll();
