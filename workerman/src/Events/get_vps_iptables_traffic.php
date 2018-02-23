<?php

return function($stdObject) {
	$cmd = '';
	foreach ($this->ipmap as $ip => $id) {
		$cmd .= '/sbin/iptables -D FORWARD -d '.$ip.' 2>/dev/null;';
		$cmd .= '/sbin/iptables -D FORWARD -s '.$ip.' 2>/dev/null;';
		// run it twice to be safe
		$cmd .= '/sbin/iptables -D FORWARD -d '.$ip.' 2>/dev/null;';
		$cmd .= '/sbin/iptables -D FORWARD -s '.$ip.' 2>/dev/null;';
		$cmd .= '/sbin/iptables -A FORWARD -d '.$ip.';';
		$cmd .= '/sbin/iptables -A FORWARD -s '.$ip.';';
	}
	`$cmd`;
};
