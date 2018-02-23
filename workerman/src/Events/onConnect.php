<?php
use Workerman\Lib\Timer;

return function($stdObject, $conn) {
	$this->conn = $conn;
	$json = array(
		'type' => 'login',
		'name' => $this->hostname,
		'module' => 'vps',
		'room_id' => 1,
		'ima' => 'host',
	);
	$conn->send(json_encode($json));
	if (!isset($this->timers['vps_get_traffic']))
		$this->timers['vps_get_traffic'] = Timer::add(60, array($this, 'vps_get_traffic'));
	$this->vps_get_list();
};
