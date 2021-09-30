<?php

return function ($stdObject) {
	$dir = __DIR__.'/../../../';
	if ($stdObject->type == 'kvm') {
		$output = trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; if [ -e /etc/dhcp/dhcpd.vps ]; then DHCPVPS=/etc/dhcp/dhcpd.vps; else DHCPVPS=/etc/dhcpd.vps; fi;  if [ -e \$DHCPVPS ]; then grep "^host" \$DHCPVPS | tr \; " " | awk '{ print $2 " " $8 }'; fi;`);
	} else {
		$output = rtrim(`/usr/sbin/vzlist -H -o veid,ip 2>/dev/null`);
	}
	$lines = explode("\n", $output);
	$ipmap = array();
	foreach ($lines as $line) {
		$parts = explode(' ', trim($line));
		if (sizeof($parts) > 1) {
			$id = $parts[0];
			$ip = $parts[1];
			if ($stdObject->validIp($ip, false) == true) {
				$extra = trim(`touch {$dir}/vps.ipmap ; export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";grep "^$ip:" {$dir}/vps.ipmap | cut -d: -f2`);
				if ($extra != '') {
					$parts = array_merge($parts, explode("\n", $extra));
				}
				for ($x = 1; $x < sizeof($parts); $x++) {
					if ($parts[$x] != '-') {
						$ipmap[$parts[$x]] = $id;
					}
				}
			}
		}
	}
	$stdObject->ipmap = $ipmap;
	return $ipmap;
};
