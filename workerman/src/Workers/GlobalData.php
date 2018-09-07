<?php
use Workerman\Worker;

$globaldata_server = new \GlobalData\Server('127.0.0.1', '55553');

// If not in the root directory, run runAll method
if (!defined('GLOBAL_START')) {
	Worker::runAll();
}
