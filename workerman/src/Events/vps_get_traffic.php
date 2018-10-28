<?php

use Workerman\Worker;

return function ($stdObject) {
    Worker::safeEcho("vps_get_traffic [0] called, calling get_vps_iptables_traffic\n");;
	$totals = $stdObject->get_vps_iptables_traffic();
    Worker::safeEcho("vps_get_traffic [1] get_vps_iptables_traffic returned ".var_export($totals, true).PHP_EOL);;
	if (sizeof($totals) > 0) {
		$stdObject->conn->send(json_encode(array(
			'type' => 'bandwidth',
			'content' => $totals,
		)));
	}
};
