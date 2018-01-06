<?php
use \Workerman\Worker;
use \Workerman\WebServer;
use \Workerman\Connection\TcpConnection;
use \Workerman\Lib\Timer;

// Command. For example 'tail -f /var/log/nginx/access.log'.
define('CMD', 'htop');
define('HEARTBEAT_TIME', 600);
// Whether to allow client input.
define('ALLOW_CLIENT_INPUT', true);

require_once __DIR__ . '/vendor/autoload.php';

$worker = new Worker("Websocket://0.0.0.0:55554");
$worker->name = 'WebsocketServer';

// start the process, open a vmstat process, and broadcast vmstat process output to all browser clients
$worker->onWorkerStart = function($worker) {
	/*
	Timer::add(60, function() use ($worker){
		$time_now = time();
		foreach ($worker->connections as $connection) {
			// It is possible that the connection has not received the message, then lastMessageTime set to the current time
			if (empty($connection->lastMessageTime)) {
				$connection->lastMessageTime = $time_now;
				continue;
			}
			if ($time_now - $connection->lastMessageTime > HEARTBEAT_TIME)
				$connection->close();
		}
	});*/
	/*
	// async wss connection to my
	$context_option = [
		// http://php.net/manual/en/context.ssl.php
		'ssl' => [
			'local_cert'        => '/your/path/to/pemfile',
			'passphrase'        => 'your_pem_passphrase',
			'allow_self_signed' => true,
			'verify_peer'       => false
		]
	];
	$ws_connection = new AsyncTcpConnection('ws://my.interserver.net:443', $context_option);
	$ws_connection->transport = 'ssl';
	$ws_connection->onConnect = function($connection){
		$connection->send('hello');
	};
	$ws_connection->onMessage = function($connection, $data){
		echo "recv: {$data}\n";
	};
	$ws_connection->onError = function($connection, $code, $msg){
		echo "error: {$msg}\n";
	};
	$ws_connection->onClose = function($connection){
		echo "connection closed\n";
	};
	$ws_connection->connect();
	*/
	// Save the process handle, close the handle when the process is closed
	$worker->process_handle = popen('vmstat 1', 'r');
	if ($worker->process_handle) {
		$process_connection = new TcpConnection($worker->process_handle);
		$process_connection->onMessage = function($process_connection, $data) use ($worker) {
			foreach($worker->connections as $connection) {
				$connection->send('vmstat:'.$data);
			}
		};
	} else {
	   echo "vmstat 1 fail\n";
	}
};

$worker->onConnect = function($connection) {
	/*
	$connection->auth_timer_id = Timer::add(30, function()use($connection){
		$connection->close();
	}, null, false);
	*/
	// vmstat
	$connection->send("vmstat:procs -----------memory---------- ---swap-- -----io---- -system-- ----cpu----\n");
	$connection->send("vmstat:r  b   swpd   free   buff  cache   si   so    bi    bo   in   cs us sy id wa\n");
	// phptty
	//To do this, PHP_CAN_DO_PTS must be enabled. See ext/standard/proc_open.c in PHP directory.
	/*$descriptorspec = [
		0 => array('pty'),
		1 => array('pty'),
		2 => array('pty')
	];*/
	/*
	//Pipe can not do PTY. Thus, many features of PTY can not be used. e.g. sudo, w3m, luit, all C programs using termios.h, etc.
	$descriptorspec = [
		0=>array("pipe", "r"),
		1=>array("pipe", "w"),
		2=>array("pipe", "w")
	];
	unset($_SERVER['argv']);
	$env = array_merge(array('COLUMNS'=>130, 'LINES'=> 50), $_SERVER);
	$connection->process = proc_open(CMD, $descriptorspec, $pipes, null, $env);
	$connection->pipes = $pipes;
	stream_set_blocking($pipes[0], 0);
	$connection->process_stdout = new TcpConnection($pipes[1]);
	$connection->process_stdout->onMessage = function($process_connection, $data)use($connection) {
		$connection->send('phptty:'.$data);
	};
	$connection->process_stdout->onClose = function($process_connection)use($connection) {
		$connection->close();   //Close WebSocket connection on process exit.
	};
	$connection->process_stdin = new TcpConnection($pipes[2]);
	$connection->process_stdin->onMessage = function($process_connection, $data)use($connection) {
		$connection->send('phptty:'.$data);
	};
	*/
};


$worker->onMessage = function($connection, $data) {
	$connection->lastMessageTime = time();
	$data = json_decode($data, true);
	switch ($data['type']) {
		case 'login':
			// delete timer if successfull
			Timer::del($connection->auth_timer_id);
			break;
		case 'phptty':
			if(ALLOW_CLIENT_INPUT)
				fwrite($connection->pipes[0], $data);
			break;
	}
};

$worker->onClose = function($connection) {
	/*
	// phptty
	$connection->process_stdin->close();
	$connection->process_stdout->close();
	fclose($connection->pipes[0]);
	$connection->pipes = null;
	proc_terminate($connection->process);
	proc_close($connection->process);
	$connection->process = null;
	*/
};

$worker->onWorkerStop = function($worker) {
	// phptty
	foreach($worker->connections as $connection) {
		$connection->close();
	}
	// vmstat
	@shell_exec('killall vmstat');
	@pclose($worker->process_handle);
};

$web = new WebServer("http://0.0.0.0:55555"); // WebServer, used to split html js css browser
$web->count = 2; // WebServer number
$web->addRoot($_SERVER['HOSTNAME'], __DIR__.'/Web'); // Set the site root
$web->addRoot('localhost', __DIR__ . '/Web');

// If not in the root directory, run runAll method
if(!defined('GLOBAL_START'))
	Worker::runAll();
