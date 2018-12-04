#!/usr/bin/env php
<?php

/**
	 * update_vps_extra_info()
	 *
	 * @return
	 */
	function update_vps_extra_info()
	{
		// ensure ethtool is installed
		`if ! which ethtool 2>/dev/null; then if [ -e /etc/redhat-release ]; then yum install -y ethtool; else apt-get install -y ethtool; fi; fi;`;
		if (in_array(trim(`hostname`), array("kvm1.trouble-free.net", "kvm2.interserver.net", "kvm50.interserver.net"))) {
			$eth = 'eth1';
		} elseif (file_exists('/etc/debian_version')) {
			if (file_exists('/sys/class/net/p2p1')) {
				$eth = 'p2p1';
			} elseif (file_exists('/sys/class/net/em1')) {
				$eth = 'em1';
			} else {
				$eth = trim(`ip link show |grep "^[0-9]"|grep -v -e "lo:" -e "br[0-9]*:"|cut -d: -f2|head -n 1|sed s#" "#""#g`);
			}
		} else {
			$eth = trim(`ip link show |grep "^[0-9]"|grep -v -e "lo:" -e "br[0-9]*:"|cut -d: -f2|head -n 1|sed s#" "#""#g`);
		}

		//$speed = trim(`ethtool $eth |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g`);
		$cmd = 'ethtool '.$eth.' |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g';
		$speed = trim(`{$cmd}`);
		$flags = explode(' ', trim(`grep "^flags" /proc/cpuinfo | head -n 1 | cut -d: -f2-;`));
		sort($flags);
		$flagsnew = implode(' ', $flags);
		$flags = $flagsnew;
		unset($flagsnew);
		$url = 'https://myvps2.interserver.net/vps_queue.php';
		$servers = array();
		$servers['speed'] = $speed;
		$servers['cpu_flags'] = $flags;
		$cmd = 'curl --connect-timeout 60 --max-time 600 -k -d action=server_info_extra -d servers="'.urlencode(base64_encode(serialize($servers))).'" "'.$url.'" 2>/dev/null;';
		// echo "CMD: $cmd\n";
		echo trim(`$cmd`);
	}

	update_vps_extra_info();
