<?php

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;

return function($stdObject) {
	$stdObject->vps_update_info();
	$stdObject->get_map_timer();
	if (isset($stdObject->timers['vps_update_info']))
		Timer::del($stdObject->timers['vps_update_info']);
	$stdObject->timers['vps_update_info'] = Timer::add($stdObject->config['timers']['vps_update_info'], array($stdObject, 'vps_update_info'));
	if (isset($stdObject->timers['vps_get_traffic']))
		Timer::del($stdObject->timers['vps_get_traffic']);
	$stdObject->timers['vps_get_traffic'] = Timer::add($stdObject->config['timers']['vps_get_traffic'], array($stdObject, 'vps_get_traffic'));
	if (isset($stdObject->timers['vps_get_list']))
		Timer::del($stdObject->timers['vps_get_list']);
	$stdObject->timers['vps_get_list'] = Timer::add($stdObject->config['timers']['get_map'], array($stdObject, 'get_map_timer'));
	if (isset($stdObject->timers['check_interval']))
		Timer::del($stdObject->timers['check_interval']);
	$stdObject->timers['check_interval'] = Timer::add($stdObject->config['heartbeat']['check_interval'], array($stdObject, 'checkHeartbeat'));
};
