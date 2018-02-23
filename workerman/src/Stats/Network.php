<?php

class NetworkStats {
	public static $network_dev = 'eth0';

	public static function update_network_dev() {
		preg_match('/^default(\s[\S]+)*\sdev\s([\S]+)/m', shell_exec('ip route'), $matches);
		self::$network_dev = $matches[2];
	}

	public static function get_network_dev() {
		return self::$network_dev;
	}

	public static function wlan_essid() {
		$info = trim(shell_exec('iwconfig'));
		if (preg_match('/ESSID:"(.*?)"\n/', $info, $m)) {
			return trim($m[1]);
		} else {
			return 'N/A';
		}
	}

	public static function network_device() {
		return self::$network_dev;
	}

	public static function mac_addr() {
		$dev = escapeshellarg(self::$network_dev);
		return trim(shell_exec('/sbin/ifconfig '.$dev.' | grep -o -E \'([[:xdigit:]]{1,2}:){5}[[:xdigit:]]{1,2}\''));
	}

	public static function ipv4_addr() {
		$dev = escapeshellarg(self::$network_dev);
		return trim(shell_exec('/sbin/ifconfig '.$dev.' | sed \'/inet\ /!d;s/.*r://g;s/\ .*//g\''));
	}

	public static function ipv6_addr() {
		$dev = escapeshellarg(self::$network_dev);
		return trim(shell_exec('ifconfig '.$dev.' | grep inet6 | awk \'{print $3}\' | head -1'));
	}

	public static function open_ports_known() {
		$info = trim(shell_exec('/bin/netstat -t -l | grep tcp | grep -v -i localhost | awk \'{print $4}\''));
		$info = str_replace("\r", "", $info);
		$output = array();
		foreach (explode("\n", $info) as $line) {
			$fragments = explode(':', $line);
			$pop = trim(array_pop($fragments));
			if (!is_numeric($pop)) {
				$output[] = $pop;
			}
		}
		if (!empty($output)) {
			return join(", ", array_unique($output));
		} else {
			return 'N/A';
		}
	}
	public static function open_ports_unknown() {
		$info = trim(shell_exec('/bin/netstat -t -l | grep tcp | grep -v -i localhost | awk \'{print $4}\''));
		$info = str_replace("\r", "", $info);
		$output = array();
		foreach (explode("\n", $info) as $line) {
			$fragments = explode(':', $line);
			$pop = trim(array_pop($fragments));
			if (is_numeric($pop)) {
				$output[] = $pop;
			}
		}
		if (!empty($output)) {
			return join(", ", array_unique($output));
		} else {
			return 'N/A';
		}
	}
	public static function dl_speed() {
		$dev = escapeshellarg(self::$network_dev);
		$first = trim(shell_exec('cat /proc/net/dev | grep '.$dev.' | awk \'{print $2}\''));
		sleep(1);
		$second = trim(shell_exec('cat /proc/net/dev | grep '.$dev.' | awk \'{print $2}\''));
		$speed = $second - $first;
		if (($speed / 1024) > 1024) {
			return round($speed / pow(1024, 2)) . " MiB/s";
		} else {
			return round($speed / 1024) . " KiB/s";
		}
	}

	 public static function ul_speed() {
		$dev = escapeshellarg(self::$network_dev);
		$first = trim(shell_exec('cat /proc/net/dev | grep '.$dev.' | awk \'{print $10}\''));
		sleep(1);
		$second = trim(shell_exec('cat /proc/net/dev | grep '.$dev.' | awk \'{print $10}\''));
		$speed = $second - $first;
		if (($speed / 1024) > 1024) {
			return round($speed / pow(1024, 2)) . " MiB/s";
		} else {
			return round($speed / 1024) . " KiB/s";
		}
	}

	public static function total_downloaded() {
		$dev = escapeshellarg(self::$network_dev);
		$info = trim(shell_exec('cat /proc/net/dev | grep '.$dev.' | awk \'{print $2}\''));
		if (($info / pow(1024, 2)) > pow(1024, 2)) {
			return round($info / pow(1024, 3)) . " GiB";
		} else {
			return round($info / pow(1024, 2)) . ' MiB';
		}
	}

	public static function total_uploaded() {
		$dev = escapeshellarg(self::$network_dev);
		$info = trim(shell_exec('cat /proc/net/dev | grep '.$dev.' | awk \'{print $10}\''));
		if (($info / pow(1024, 2)) > pow(1024, 2)) {
			return round($info / pow(1024, 3)) . " GiB";
		} else {
			return round($info / pow(1024, 2)) . ' MiB';
		}
	}


	/* from some prober */

	public static function curl($url) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_TIMEOUT, 2);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$r = curl_exec($ch);
		$curl_errno = curl_errno($ch);
		curl_close($ch);
		if($curl_errno > 0) return false;
		return $r;
	}

	public static function network() {
		$net = file("/proc/net/dev");
		$netname = array('enp2s0','eth1','eth0','venet0','ens18');
		$dev = array();
		for($i=2;$i<count($net);$i++) {
			$linenow = $net[$i];
			$arrnow = explode(':', $linenow);
			for($x=0;$x<count($netname);$x++) {
				$namenow = $netname[$x];
				if(strstr($arrnow[0], $namenow)) {
					$strs = $linenow;
				} else {
					continue;
				}
			}
		}
		preg_match_all( "/([^\s]+):[\s]{0,}(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/", $strs, $info );
		$return['in'] = $info[2][0];
		$return['out'] = $info[10][0];
		return $return;
	}

	public static function Checkipv6() {
		$ping = @file_get_contents('http://[2001:da8:d800::1]/cgi-bin/myipv6addr');
		if(!$ping) {
			return false;
		} else {
			return true;
		}
	}

	public static function Getipv4() {
		$ipipurl = 'http://ip.huomao.com/ip';
		$ipipjson = self::curl($ipipurl);
		$ipiparr = json_decode($ipipjson,true);
		return $ipiparr['ip'];
	}

	/* this parts from tupa */

	/**
	 * Gets network interface informations
	 *
	 * @return array	network information
	 */
	public static function network2() {
		$results = array();
		if ($output = explode("\n", rtrim(file_get_contents('/proc/net/dev')))) {
			while (list(,$buf) = each($output)) {
				if (preg_match('/:/', $buf)) {
					list($dev_name, $stats_list) = preg_split('/:/', $buf, 2);
					$stats = preg_split('/\s+/', trim($stats_list));
					$results[$dev_name] = array();

					$results[$dev_name]['rx_bytes'] = $stats[0];
					$results[$dev_name]['rx_packets'] = $stats[1];
					$results[$dev_name]['rx_errs'] = $stats[2];
					$results[$dev_name]['rx_drop'] = $stats[3];

					$results[$dev_name]['tx_bytes'] = $stats[8];
					$results[$dev_name]['tx_packets'] = $stats[9];
					$results[$dev_name]['tx_errs'] = $stats[10];
					$results[$dev_name]['tx_drop'] = $stats[11];

					$results[$dev_name]['errs'] = $stats[2] + $stats[10];
					$results[$dev_name]['drop'] = $stats[3] + $stats[11];
				}
			}
		}
		return $results;
	}
}
