<?php
use Workerman\Lib\Timer;

return function($stdObject, $conn) {
	$stdObject->conn = $conn;
	$json = array(
		'type' => 'login',
		'name' => $stdObject->hostname,
		'module' => 'vps',
		'room_id' => 1,
		'ima' => 'host',
	);
	$conn->send(json_encode($json));
	if (!isset($stdObject->timers['vps_get_traffic']))
		$stdObject->timers['vps_get_traffic'] = Timer::add(60, array($stdObject, 'vps_get_traffic'));
	$stdObject->vps_get_list();
};
