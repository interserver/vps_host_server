<?php
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

/**
* ran periodicaly to update our vps mapping files
*/
return function($stdObject) {
	if ($stdObject->debug === true)
		Worker::safeEcho('Pong Timer Send pong'.PHP_EOL);
	$stdObject->conn->send(json_encode(['type' => 'pong'])); // send pong request to hub
};
