<?php

use \Workerman\Worker;
use \GatewayWorker\Gateway;
use \Workerman\Autoloader;

$gateway = new Gateway("Websocket://0.0.0.0:7272");
$gateway->name = 'ChatGateway';
$gateway->count = 4; // Set the number of processes, the number of gateway process recommendations and cpu the same
$gateway->lanIp = '127.0.0.1'; // When distributed deployment set to intranet ip (non 127.0.0.1)
$gateway->startPort = 2300; // Internal communication start port. If $ gateway-> count = 4, the starting port is 2300. 2300 2301 2302 2303 4 ports are generally used as the internal communication port
$gateway->pingInterval = 10; // Heartbeat interval
$gateway->pingData = '{"type":"ping"}'; // heartbeat data
$gateway->registerAddress = '127.0.0.1:1236'; // Service registration address

/* $gateway->onConnect = function($connection) { // When the client is connected, set the connection onWebSocketConnect, that is, when the websocket handshake callback
	$connection->onWebSocketConnect = function($connection , $http_header) {
		if($_SERVER['HTTP_ORIGIN'] != 'http://chat.workerman.net') // Here you can determine whether the source of the connection is legal, illegal to turn off the connection.  $_SERVER['HTTP_ORIGIN'] Identifies which site's web-initiated websocket link
			$connection->close();
		// onWebSocketConnect Inside $_GET $_SERVER is available  var_dump($_GET, $_SERVER);
	};
}; */

// If it is not started in the root directory, run the runAll method
if(!defined('GLOBAL_START'))
	Worker::runAll();
