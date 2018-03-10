<?php

return function($stdObject) {
	$totals = $stdObject->get_vps_iptables_traffic();
	if (sizeof($totals) > 0)
		$stdObject->conn->send(json_encode(array(
			'type' => 'bandwidth',
			'content' => $totals,
		)));
};
