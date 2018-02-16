#!/usr/bin/env php
<?php
/**
 * VPS Hosting Daemon - Outline/Todo: *
 * - it acts as a permanent client connecting to our central websocket server
 * - instead of periodically checking for new queued items it will get sent notifications via its client
 *   ws connection for queues.   the central server will periodically check the queue and if theres something
 *   new itw ill push notifications to the appropriate server.   this should cut back on queries and resource usage.
 * - it will use ssl for its communications and add some authentication type layer of security
 * - the 'get vps list' will be put on a much slower timer as it doesnt need to be updating that often.  after any
 *   queue commands are received though it should follow it up with another 'get vps list'.   apart from that when
 *   it does get a listing it should store the results internally as well and if they are the same as the last thing
 *   we sent then dont bother pushing a new list update
 * - asynchronous.  if we're doing some command that takes a while run it as a piped
 *   process or similar so we can handle it asynchronously and the workers dont get backed up.
 * - running commands.  there should be a message type that allows the central server to push a command to it to
 *   run and proxy the output through the ws.  2 examples of this are the VMStat live graph i have setup to have
 *   the server run 'vmstat 1' in a pipe and send the output over the ws connection and accept input from it to
 *   redirect to the pipe.   The other example is the PHPTTY page which provides basic terminal emulation to a
 *   browser.   on this side a command would be run and i/o redirected over the ws connection.
 * - improved bandwidth.   bandwidth needs to be sending an update every minute, at lesat tentativeley .   This
 *   will work well with RRD although not sure if we're going to use that yet or not.. despite already coding
 *   things to store it and all that..will have to see how it plays out on the disk IO
 * - easily updated.  needs a mechanism to allow it to easily received updates and reload itself w/ the new updates.
 * - make it easily expandable. eventually we'll want to easily add custom commands and handling for the chat/ws side to
 *   be able to use so things should be setup in a way that allows this.
 */
use Workerman\Worker;
//use Workerman\WebServer;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;

$composer = include __DIR__.'/vendor/autoload.php';
global $settings;
$settings = include __DIR__.'/settings.php';
include_once __DIR__.'/functions.php';
include_once __DIR__.'/Events.php';
include_once __DIR__.'/SystemStats.php';
include_once __DIR__.'/../xml2array.php';

if (ini_get('date.timezone') == '')
	ini_set('date.timezone', 'America/New_York');
if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1)
	ini_set('default_socket_timeout', 1200);

$globaldata_server = new \GlobalData\Server($settings['servers']['globaldata']['ip'], $settings['servers']['globaldata']['port']);

$task_worker = new Worker('Text://'.$settings['servers']['task']['ip'].':'.$settings['servers']['task']['port']); // task worker, using the Text protocol
$task_worker->count = $settings['servers']['task']['count']; // number of task processes can be opened more than needed
$task_worker->name = 'TaskWorker';
$task_worker->onWorkerStart = function($worker) {
	global $global, $settings;
	$global = new \GlobalData\Client($settings['servers']['globaldata']['ip'].':'.$settings['servers']['globaldata']['port']); // initialize the GlobalData client
};
$task_worker->onMessage = function($connection, $task_data) {
	$task_data = json_decode($task_data, true);			// Suppose you send json data
	if (isset($task_data['function'])) {				// According to task_data to deal with the corresponding task logic
		echo "Starting Task {$task_data['function']}\n";
		//require_once __DIR__.'/../'.$task_data['function'].'.php';
		$return = isset($task_data['args']) ? call_user_func($task_data['function'], $task_data['args']) : call_user_func($task_data['function']);
		echo "Ending Task {$task_data['function']}\n";
		$connection->send(json_encode($return));			// send the result
	}
};

//$worker = new Worker('Websocket://'.$settings['servers']['ws']['ip'].':'.$settings['servers']['ws']['port']);
$worker = new Worker();
$worker->name = 'VpsServer';
//$worker->name = 'WebsocketServer';
// start the process, open a vmstat process, and broadcast vmstat process output to all browser clients
$worker->onWorkerStart = function($worker) {
	global $global, $settings, $events;
	$events = new Events();
	$global = new \GlobalData\Client($settings['servers']['globaldata']['ip'].':'.$settings['servers']['globaldata']['port']);	 // initialize the GlobalData client
	if (!isset($global->settings))
		$global->settings = $settings;
	if($worker->id === 0) { // The timer is set only on the process whose id number is 0, and the processes of other 1, 2, and 3 processes do not set the timer
		//$events->timers['vps_update_info_timer'] = Timer::add($global->settings['timers']['vps_update_info'], 'vps_update_info_timer');
		//$events->timers['vps_queue_timer'] = Timer::add($global->settings['timers']['vps_queue'], 'vps_queue_timer');

	}
	$context = [																						// Certificate is best to apply for a certificate
		'ssl' => [									// use the absolute/full paths
			'local_cert' => __DIR__.'/myadmin.crt',
			'local_pk' => __DIR__.'/myadmin.key',
			'verify_peer' => false,
			'verify_peer_name' => false,
		]
	];
	$ws_connection= new AsyncTcpConnection('ws://my3.interserver.net:7272', $context);
	$ws_connection->transport = 'ssl';

	$ws_connection->onConnect = [$events, 'onConnect'];
	$ws_connection->onMessage = [$events, 'onMessage'];
	$ws_connection->onError = [$events, 'onError'];
	$ws_connection->onClose = [$events, 'onClose'];
	$ws_connection->connect();
};
/*
$worker->onConnect = function($connection) {
	global $global;
	if ($global->settings['auth']['enable'] === TRUE) {
		$connection->auth_timer_id = Timer::add(30, function() use ($connection){
			$connection->close();
		}, null, false);
	}
	if ($global->settings['vmstat']['enable'] === TRUE) {
		$connection->send("vmstat:procs -----------memory---------- ---swap-- -----io---- -system-- ----cpu----\n");
		$connection->send("vmstat:r  b   swpd   free   buff  cache   si   so    bi    bo   in   cs us sy id wa\n");
	}
	if ($global->settings['phptty']['enable'] === TRUE) {
		//To do this, PHP_CAN_DO_PTS must be enabled. See ext/standard/proc_open.c in PHP directory.
		//$descriptorspec = [
		//	0 => ['pty'],
		//	1 => ['pty'],
		//	2 => ['pty']
		//];
		//Pipe can not do PTY. Thus, many features of PTY can not be used. e.g. sudo, w3m, luit, all C programs using termios.h, etc.
		$descriptorspec = [
			0 => ['pipe','r'],
			1 => ['pipe','w'],
			2 => ['pipe','w']
		];
		unset($_SERVER['argv']);
		$env = array_merge(['COLUMNS' => 130, 'LINES' => 50], $_SERVER);
		$connection->process = proc_open($global->settings['phptty']['cmd'], $descriptorspec, $pipes, null, $env);
		$connection->pipes = $pipes;
		stream_set_blocking($pipes[0], 0);
		$connection->process_stdout = new TcpConnection($pipes[1]);
		$connection->process_stdout->onMessage = function($process_connection, $data) use ($connection) {
			$connection->send('phptty:'.$data);
		};
		$connection->process_stdout->onClose = function($process_connection) use ($connection) {
			$connection->close(); // Close WebSocket connection on process exit.
		};
		$connection->process_stdin = new TcpConnection($pipes[2]);
		$connection->process_stdin->onMessage = function($process_connection, $data) use ($connection) {
			$connection->send('phptty:'.$data);
		};
	}
};

$worker->onClose = function($connection) {
	global $global;
	if ($global->settings['phptty']['enable'] === TRUE) {
		$connection->process_stdin->close();
		$connection->process_stdout->close();
		fclose($connection->pipes[0]);
		$connection->pipes = null;
		proc_terminate($connection->process);
		proc_close($connection->process);
		$connection->process = null;
	}
};

$worker->onWorkerStop = function($worker) {
	global $global, $settings;
	if ($settings['phptty']['enable'] === TRUE) {
		foreach($worker->connections as $connection)
			$connection->close();
	}
	if ($settings['vmstat']['enable'] === TRUE) {
		@shell_exec('killall vmstat');
		@pclose($worker->process_handle);
	}
};
*/
// If not in the root directory, run runAll method
if(!defined('GLOBAL_START'))
	Worker::runAll();
