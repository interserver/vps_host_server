<?php
use Workerman\Connection\AsyncTcpConnection;

return function($stdObject) {
	global $global, $settings;
	//echo 'Map Timer Startup'.PHP_EOL;
	do {
	} while ($global->cas('busy', 0, 1));
	$conn = $stdObject->conn;
	//echo 'Map Timer Send get_map'.PHP_EOL;
	$conn->send(json_encode([
		'type' => 'get_map'
	]));
	$global->busy = 0;
	//echo 'Map Timer End'.PHP_EOL;
};
