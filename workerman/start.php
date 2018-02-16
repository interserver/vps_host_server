#!/usr/bin/env php
<?php
/**
 * VPS Hosting Daemon - Outline/Todo:
 *
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
 *   redirect to the pipe.   The other example is the PHPTTY page which provi                                                                    des basic terminal emulation to a
 *   browser.   on this side a command would be run and i/o redirected over the ws connection.
 * - improved bandwidth.   bandwidth needs to be sending an update every minute, at lesat tentativeley .   This
 *   will work well with RRD although not sure if we're going to use that yet or not.. despite already coding
 *   things to store it and all that..will have to see how it plays out on the disk IO
 * - easily updated.  needs a mechanism to allow it to easily received updates and reload itself w/ the new updates.
 * - make it easily expandable. eventually we'll want to easily add custom commands and handling for the chat/ws side to
 *   be able to use so things should be setup in a way that allows this.
 */

use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;

$composer = include __DIR__.'/vendor/autoload.php';
$settings = include __DIR__.'/settings.php';
include_once __DIR__.'/../xml2array.php';
include_once __DIR__.'/functions.php';
include_once __DIR__.'/Events.php';
include_once __DIR__.'/SystemStats.php';

if (ini_get('date.timezone') == '')
	ini_set('date.timezone', 'America/New_York');
if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1)
	ini_set('default_socket_timeout', 1200);

$globaldata_server = new \GlobalData\Server($settings['servers']['globaldata']['ip'], $settings['servers']['globaldata']['port']);

$task_worker = new Worker('Text://'.$settings['servers']['task']['ip'].':'.$settings['servers']['task']['port']);
$task_worker->count = $settings['servers']['task']['count'];
$task_worker->name = 'TaskWorker';
$task_worker->onWorkerStart = function($worker) {
	global $global, $settings;
	$global = new \GlobalData\Client($settings['servers']['globaldata']['ip'].':'.$settings['servers']['globaldata']['port']);
};
$task_worker->onMessage = function($connection, $task_data) {
	$task_data = json_decode($task_data, true);
	if (isset($task_data['function'])) {
		echo "Starting Task {$task_data['function']}\n";
		$return = isset($task_data['args']) ? call_user_func($task_data['function'], $task_data['args']) : call_user_func($task_data['function']);
		echo "Ending Task {$task_data['function']}\n";
		$connection->send(json_encode($return));
	}
};

$worker = new Worker();
$worker->name = 'VpsServer';
$worker->onWorkerStart = function($worker) {
	global $global, $settings, $events;
	$events = new Events();
	$global = new \GlobalData\Client($settings['servers']['globaldata']['ip'].':'.$settings['servers']['globaldata']['port']);	 // initialize the GlobalData client
	if (!isset($global->settings))
		$global->settings = $settings;
	$events->onWorkerStart($worker);
	$ws_connection= new AsyncTcpConnection('ws://my3.interserver.net:7272', Events::getSslContext());
	$ws_connection->transport = 'ssl';
	$ws_connection->onConnect = [$events, 'onConnect'];
	$ws_connection->onMessage = [$events, 'onMessage'];
	$ws_connection->onError = [$events, 'onError'];
	$ws_connection->onClose = [$events, 'onClose'];
	$ws_connection->onWorkerStop = [$events, 'onWorkerStop'];
	$ws_connection->connect();
};

// If not in the root directory, run runAll method
if(!defined('GLOBAL_START'))
	Worker::runAll();