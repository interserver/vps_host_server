<?php 

use \Workerman\Worker;
use \GatewayWorker\Register;

// register service must be a text protocol
$register = new Register('text://0.0.0.0:1236');

// If it is not started in the root directory, run the runAll method
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}

