<?php

use \Workerman\Worker;
use \Workerman\WebServer;
use \GatewayWorker\Gateway;
use \GatewayWorker\BusinessWorker;
use \Workerman\Autoloader;

$web = new WebServer("http://0.0.0.0:55151"); // WebServer
$web->count = 2; // WebServer number of processes
$web->addRoot('www.your_domain.com', __DIR__.'/Web'); // Set the site root directory

if(!defined('GLOBAL_START')) // If it is not started in the root directory, run the runAll method
	Worker::runAll();
