<?php

use \Workerman\Worker;
use \GatewayWorker\Gateway;
use \Workerman\Autoloader;

$gateway = new Gateway("Websocket://0.0.0.0:7272");
$gateway->name = 'ChatGateway';
// Set the number of processes, the number of gateway process recommendations and cpu the same
$gateway->count = 4;
// When distributed deployment set to intranet ip (non 127.0.0.1)
$gateway->lanIp = '127.0.0.1';
// Internal communication start port. If $ gateway-> count = 4, the starting port is 2300
// 2300 2301 2302 2303 4 ports are generally used as the internal communication port
$gateway->startPort = 2300;
// Heartbeat interval
$gateway->pingInterval = 10;
// heartbeat data
$gateway->pingData = '{"type":"ping"}';
// Service registration address
$gateway->registerAddress = '127.0.0.1:1236';

/*
// When the client is connected, set the connection onWebSocketConnect, that is, when the websocket handshake callback
$gateway->onConnect = function($connection)
{
	$connection->onWebSocketConnect = function($connection , $http_header)
	{
		// Here you can determine whether the source of the connection is legal, illegal to turn off the connection
		// $_SERVER['HTTP_ORIGIN'] Identifies which site's web-initiated websocket link
		if($_SERVER['HTTP_ORIGIN'] != 'http://chat.workerman.net')
			$connection->close();
		// onWebSocketConnect Inside $_GET $_SERVER is available
		// var_dump($_GET, $_SERVER);
	};
};
*/

// If it is not started in the root directory, run the runAll method
if(!defined('GLOBAL_START'))
	Worker::runAll();

