<?php

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;

return function($stdObject, $conn, $data) {
	$stdObject->conn = $conn;
	$stdObject->vps_update_info();
	$stdObject->get_map_timer();
	if (!isset($stdObject->timers['vps_update_info']))
		$stdObject->timers['vps_update_info'] = Timer::add(600, array($stdObject, 'vps_update_info'));
	if (!isset($stdObject->timers['vps_get_traffic']))
		$stdObject->timers['vps_get_traffic'] = Timer::add(60, array($stdObject, 'vps_get_traffic'));
	//if (!isset($stdObject->timers['vps_get_cpu']))
	if (!isset($stdObject->timers['vps_get_list']))
		$stdObject->timers['vps_get_list'] = Timer::add(600, array($stdObject, 'get_map_timer'));
};
