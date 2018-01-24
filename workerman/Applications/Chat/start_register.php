<?php

use \Workerman\Worker;
use \GatewayWorker\Register;

$register = new Register('text://0.0.0.0:1236'); // register service must be a text protocol

if(!defined('GLOBAL_START')) // If it is not started in the root directory, run the runAll method
	Worker::runAll();
