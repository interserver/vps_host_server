<?php
use Workerman\Connection\AsyncTcpConnection;

return function($stdObject) {
	global $global, $settings;
	if ($global->cas('busy', 0, 1)) {
		$conn = $stdObject->conn;
		$conn->send(json_encode([
			'type' => 'get_map'
		]));
		$global->busy = 0;
	}
};
