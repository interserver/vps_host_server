<?php

use \Workerman\Lib\Timer;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;

class Events {
	public $var;
	public $vps_list = [];
	public $bandwidth = null;
	public $timers = [];
	public $ipmap = [];
	public $type;

	public function __construct() {
		$this->type = file_exists('/usr/sin/vzctl') ? 'vzctl' : 'kvm';
		//Events::update_network_dev();
		$this->get_vps_ipmap();
	}

	public function onConnect($conn) {
		$conn->send('{"type":"login","client_name":"'.$_SERVER['HOSTNAME'].'","room_id":"1"}');
	}

	public function onMessage($conn, $data) {
		echo $data.PHP_EOL;
		global $global;
		$conn->lastMessageTime = time();
		$data = json_decode($data, true);
		switch ($data['type']) {
			case 'ping':
				$conn->send('{"type":"pong"}');
				break;
			case 'login':
				break;
			case 'phptty':
				if ($global->settings['phptty']['client_input'] === TRUE)
					fwrite($conn->pipes[0], $data);
				break;
		}
	}

	public function onError($connection, $code, $msg){
		echo "error: {$msg}\n";
	}

	public function onClose($conn) {
		echo 'Connection Closed, Shutting Down'.PHP_EOL;
		//$conn->close();
		Worker::stopAll();
	}


	public function get_vps_ipmap() {
		if ($this->type = 'kvm')
			$output = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; if [ -e /etc/dhcp/dhcpd.vps ]; then DHCPVPS=/etc/dhcp/dhcpd.vps; else DHCPVPS=/etc/dhcpd.vps; fi;  grep "^host" \$DHCPVPS | tr \; " " | awk '{ print $2 " " $8 }'`);
		else
			$output = rtrim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzlist -H -o veid,ip 2>/dev/null`);
		$lines = explode("\n", $output);
		$ips = array();
		foreach ($lines as $line) {
			$parts = explode(' ', trim($line));
			if (sizeof($parts) > 1) {
				$id = $parts[0];
				$ip = $parts[1];
				if (validIp($ip, false) == true) {
					$extra = trim(`touch /root/cpaneldirect/vps.ipmap ; export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";grep "^$ip:" /root/cpaneldirect/vps.ipmap | cut -d: -f2`);
					if ($extra != '')
						$parts = array_merge($parts, explode("\n", $extra));
					for ($x = 1; $x < sizeof($parts); $x++)
						if ($parts[$x] != '-')
							$ips[$parts[$x]] = $id;
				}
			}
		}
		$this->ipmap = $ips;
		return $ips;
	}

	public function vps_iptables_traffic_rules() {
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
	}

	public function get_vps_iptables_traffic() {
		$totals = array();
		if ($this->type == 'kvm') {
			if (is_null($this->bandwidth))
				$this->bandwidth = unserialize(file_get_contents('/root/.traffic.last'));
			$vnetcounters = trim(`grep vnet /proc/net/dev | tr : " " | awk '{ print $1 " " $2 " " $10 }'`);
			if ($vnetcounters != '') {
				$vnetcounters = explode("\n", $vnetcounters);
				$vnets = array();
				foreach ($vnetcounters as $line) {
					list($vnet, $out, $in) = explode(' ', $line);
					//echo "Got    VNet:$vnet   IN:$in    OUT:$out\n";
					$vnets[$vnet] = array('in' => $in, 'out' => $out);
				}
				$cmd = 'grep -H -i fe /sys/devices/virtual/net/vnet*/address 2>/dev/null| sed s#"/sys/devices/virtual/net/\([^/]*\)/address:fe:\(.*\)$"#"\1 52:\2"#g';
				$vnetmacs = trim(`$cmd`);
				if ($vnetmacs != '') {
					$vnetmacs = explode("\n", $vnetmacs);
					$macs = array();
					foreach ($vnetmacs as $line) {
						list($vnet, $mac) = explode(' ', $line);
						//echo "Got  VNet:$vnet   Mac:$mac\n";
						$vnets[$vnet]['mac'] = $mac;
						$macs[$mac] = $vnet;
					}
					$cmd = 'if [ -e /etc/dhcp/dhcpd.vps ]; then cat /etc/dhcp/dhcpd.vps; else cat /etc/dhcpd.vps; fi | grep ethernet | sed s#"^host \([a-z0-9\.]*\) { hardware ethernet \([^;]*\); fixed-address \([0-9\.]*\);}$"#"\2 \1 \3"#g';
					$macvps = explode("\n", trim(`$cmd`));
					$vpss = array();
					foreach ($macvps as $line) {
						list($mac, $vps, $ip) = explode(' ', $line);
						//echo "Got  Mac:$mac   VPS:$vps   IP:$ip\n";
						if (isset($macs[$mac]) && isset($vnets[$macs[$mac]])) {
							$vpss[$vps] = $vnets[$macs[$mac]];
							$vpss[$vps]['ip'] = $ip;
							if (isset($last) && isset($vpss[$vps])) {
								$in_new = bcsub($vpss[$vps]['in'], $last[$vps]['in'], 0);
								$out_new = bcsub($vpss[$vps]['out'], $last[$vps]['out'], 0);
							}
							elseif (isset($last))
							{
								$in_new = $last[$vps]['in'];
								$out_new = $last[$vps]['out'];
							} else {
								$in_new = $vpss[$vps]['in'];
								$out_new = $vpss[$vps]['out'];
							}
							if ($in_new > 0 || $out_new > 0)
								$totals[$ip] = array('in' => $in_new, 'out' => $out_new);
						}
					}
					if (sizeof($totals) > 0) {
						$this->bandwidth = $vpss;
						file_put_contents('/root/.traffic.last', serialize($vpss));
					}
				}
			}
		} else {
			foreach ($ips as $ip => $id) {
				if (validIp($ip, false) == true) {
					$lines = explode("\n", trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; iptables -nvx -L FORWARD 2>/dev/null | grep -v DROP  | awk '{ print " " $7 " " $8 " " $2 }' | grep -vi "[a-z]" | sort -n | grep " $ip " | awk '{ print $3 }'`));
					if (sizeof($lines) == 2) {
						list($in,$out) = $lines;
						$total = $in + $out;
						if ($total > 0)
							$totals[$ip] = array('in' => $in, 'out' => $out);
					}
				}
			}
			`PATH="\$PATH:/sbin:/usr/sbin"  iptables -Z`;
			$this->vps_iptables_traffic_rules();
		}
		return $totals;
	}

	public function vps_get_traffic() {
		$totals = $this->get_vps_iptables_traffic();
		echo "Got Totals:".print_r($totals, TRUE).PHP_EOL;
	}
}
