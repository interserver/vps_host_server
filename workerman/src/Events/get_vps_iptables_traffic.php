<?php

use Workerman\Worker;

return function ($stdObject) {
    //Worker::safeEcho("get_vps_iptables_traffic [0] Starting up processing for type '{$stdObject->type}'\n");
	$totals = array();
	if ($stdObject->type == 'kvm') {
		if (is_null($stdObject->traffic_last) && file_exists('/root/.traffic.last')) {
            $stdObject->traffic_last = json_decode(file_get_contents('/root/.traffic.last'), true);
            if (is_null($stdObject->traffic_last) && $stdObject->traffic_last === false) {
			    $stdObject->traffic_last = unserialize(file_get_contents('/root/.traffic.last'));
            }
		}
		$vnetcounters = trim(`grep vnet /proc/net/dev | tr : " " | awk '{ print $1 " " $2 " " $10 }'`);
		if ($vnetcounters != '') {
			$vnetcounters = explode("\n", $vnetcounters);
			$vnets = array();
			foreach ($vnetcounters as $line) {
				list($vnet, $out, $in) = explode(' ', $line);
				//Worker::safeEcho("get_vps_iptables_traffic [1] Got    VNet:$vnet   IN:$in    OUT:$out\n");
				$vnets[$vnet] = array('in' => $in, 'out' => $out);
			}
			$cmd = 'grep -H -i fe /sys/devices/virtual/net/vnet*/address 2>/dev/null| sed s#"/sys/devices/virtual/net/\([^/]*\)/address:fe:\(.*\)$"#"\1 52:\2"#g';
			$vnetmacs = trim(`$cmd`);
			if ($vnetmacs != '') {
				$vnetmacs = explode("\n", $vnetmacs);
				$macs = array();
				foreach ($vnetmacs as $line) {
					list($vnet, $mac) = explode(' ', $line);
                    $mac = preg_replace('/^52:16:3e:/', '00:16:3e:', $mac);
					//Worker::safeEcho("get_vps_iptables_traffic [2] Got  VNet:$vnet   Mac:$mac\n");
					$vnets[$vnet]['mac'] = $mac;
					$macs[$mac] = $vnet;
				}
				$cmd = 'if [ -e /etc/dhcp/dhcpd.vps ]; then cat /etc/dhcp/dhcpd.vps; else cat /etc/dhcpd.vps; fi | grep ethernet | sed s#"^host \([a-z0-9\.]*\) { hardware ethernet \([^;]*\); fixed-address \([0-9\.]*\);}$"#"\2 \1 \3"#g';
				$macvps = explode("\n", trim(`$cmd`));
				$vpss = array();
				foreach ($macvps as $line) {
					list($mac, $vps, $ip) = explode(' ', $line);
					//Worker::safeEcho("get_vps_iptables_traffic [3] Got  Mac:$mac   VPS:$vps   IP:$ip\n");
					if (isset($macs[$mac]) && isset($vnets[$macs[$mac]])) {
						$vpss[$vps] = $vnets[$macs[$mac]];
						$vpss[$vps]['ip'] = $ip;
						if (isset($last) && isset($vpss[$vps])) {
							$in_new = bcsub($vpss[$vps]['in'], $last[$vps]['in'], 0);
							$out_new = bcsub($vpss[$vps]['out'], $last[$vps]['out'], 0);
						} elseif (isset($last)) {
							$in_new = $last[$vps]['in'];
							$out_new = $last[$vps]['out'];
						} else {
							$in_new = $vpss[$vps]['in'];
							$out_new = $vpss[$vps]['out'];
						}
						if ($in_new > 0 || $out_new > 0) {
							$totals[$ip] = array('vps' => $vps, 'in' => $in_new, 'out' => $out_new);
						}
					}
				}
				if (sizeof($totals) > 0) {
					$stdObject->traffic_last = $vpss;
					file_put_contents('/root/.traffic.last', json_encode($vpss));
				}
			}
		}
	} else {
		foreach ($stdObject->ipmap as $ip => $id) {
			if ($stdObject->validIp($ip, false) == true) {
				$lines = explode("\n", trim(`/sbin/iptables -nvx -L FORWARD 2>/dev/null | grep -v DROP  | awk '{ print " " $7 " " $8 " " $2 }' | grep -vi "[a-z]" | sort -n | grep " $ip " | awk '{ print $3 }'`));
				if (sizeof($lines) == 2) {
					list($in, $out) = $lines;
					$total = $in + $out;
					if ($total > 0) {
						$totals[$ip] = array('vps' => $id, 'in' => $in, 'out' => $out);
					}
				}
			}
		}
		`PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/sbin:/usr/sbin"  iptables -Z`;
		$stdObject->vps_iptables_traffic_rules();
	}
	$stdObject->bandwidth = $totals;
	return $totals;
};
