<?php

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;

return function ($stdObject) {
	$stdObject->vps_update_info();
	$stdObject->get_map_timer();
    $stdObject->addTimer('vps_update_info');
    $stdObject->addTimer('vps_get_traffic');
    $stdObject->addTimer('vps_get_list', $stdObject->config['timers']['get_map']);
    $stdObject->addTimer('check_interval', $stdObject->config['heartbeat']['check_interval'], array($stdObject, 'checkHeartbeat'));
};
