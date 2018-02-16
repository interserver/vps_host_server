<?php
use Workerman\Worker;

$globaldata_server = new \GlobalData\Server($settings['servers']['globaldata']['ip'], $settings['servers']['globaldata']['port']);

// If not in the root directory, run runAll method
if(!defined('GLOBAL_START'))
	Worker::runAll();