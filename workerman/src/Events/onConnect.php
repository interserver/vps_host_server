<?php
use Workerman\Lib\Timer;
use Workerman\Worker;

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
	if (!isset($stdObject->timers['vps_update_info']))
		$stdObject->timers['vps_update_info'] = Timer::add(600, array($stdObject, 'vps_update_info'));
	if (!isset($stdObject->timers['vps_get_traffic']))
		$stdObject->timers['vps_get_traffic'] = Timer::add(60, array($stdObject, 'vps_get_traffic'));
//	if (!isset($stdObject->timers['vps_get_cpu']))
//		$stdObject->timers['vps_get_cpu'] = Timer::add(60, array($stdObject, 'vps_get_cpu'));
	if (!isset($stdObject->timers['vps_get_list']))
		$stdObject->timers['vps_get_list'] = Timer::add(600, array($stdObject, 'get_map_timer'));
	$stdObject->vps_update_info();
	$stdObject->get_map_timer();
};
