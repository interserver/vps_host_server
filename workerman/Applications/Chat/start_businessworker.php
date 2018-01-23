<?php

use \Workerman\Worker;
use \GatewayWorker\BusinessWorker;
use \Workerman\Autoloader;

// bussinessWorker process
$worker = new BusinessWorker();
// worker name
$worker->name = 'ChatBusinessWorker';
// bussinessWorker number of processes
$worker->count = 4;
// Service registration address
$worker->registerAddress = '127.0.0.1:1236';

// If it is not started in the root directory, run the runAll method
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}

