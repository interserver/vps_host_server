<?php

return function($stdObject) {
	$totals = $this->get_vps_iptables_traffic();
	$this->conn->send(json_encode(array(
		'type' => 'bandwidth',
		'content' => $totals,
	)));
};
