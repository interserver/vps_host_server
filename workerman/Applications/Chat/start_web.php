<?php 

use \Workerman\Worker;
use \Workerman\WebServer;
use \GatewayWorker\Gateway;
use \GatewayWorker\BusinessWorker;
use \Workerman\Autoloader;

// WebServer
$web = new WebServer("http://0.0.0.0:55151");
// WebServer number of processes
$web->count = 2;
// Set the site root directory
$web->addRoot('www.your_domain.com', __DIR__.'/Web');

// If it is not started in the root directory, run the runAll method
if(!defined('GLOBAL_START'))
    Worker::runAll();

