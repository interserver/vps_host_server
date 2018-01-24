<?php
use \Workerman\Worker;
use \Workerman\WebServer;
use \Workerman\Connection\TcpConnection;

require_once __DIR__ . '/vendor/autoload.php';
$worker = new Worker('Websocket://0.0.0.0:7777');
$worker->name = 'VMStatWorker';
// start the process, open a vmstat process, and broadcast vmstat process output to all browser clients
$worker->onWorkerStart = function($worker) {
	// Save the process handle, close the handle when the process is closed
	$worker->process_handle = popen('vmstat 1', 'r');
	if ($worker->process_handle) {
		$process_connection = new TcpConnection($worker->process_handle);
		$process_connection->onMessage = function($process_connection, $data) use ($worker) {
			foreach($worker->connections as $connection) {
				$connection->send($data);
			}
		};
	} else {
	   echo "vmstat 1 fail\n";
	}
};

// when the process is closed
$worker->onWorkerStop = function($worker) {
	@shell_exec('killall vmstat');
	@pclose($worker->process_handle);
};

$worker->onConnect = function($connection) {
	$connection->send("procs -----------memory---------- ---swap-- -----io---- -system-- ----cpu----\n");
	$connection->send("r  b   swpd   free   buff  cache   si   so    bi    bo   in   cs us sy id wa\n");
};

$web = new WebServer("http://0.0.0.0:55555"); // WebServer, used to split html js css browser
$web->count = 2; // WebServer number
$web->addRoot($_SERVER['HOSTNAME'], __DIR__.'/Web'); // Set the site root

// If not in the root directory, run runAll method
if(!defined('GLOBAL_START'))
{
	Worker::runAll();
}

