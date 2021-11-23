<?php
namespace App\Command\CronCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class BwInfoCommand extends Command {
	public function brief() {
		return "lists the history entries";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
		$opts->add('a|all', 'Use All Available HD, CPU Cores, and 70% RAM');
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
	}

	public function execute() {
		Vps::init($this->getOptions(), []);
		/** @var {\GetOptionKit\OptionResult|GetOptionKit\OptionCollection} */
		$opts = $this->getOptions();
        $useAll = array_key_exists('all', $opts->keys) && $opts->keys['all']->value == 1;
		//$url = 'https://mynew.interserver.net/vps_queue.php';
		$url = 'http://mynew.interserver.net:55151/queue.php';
		$ips = $this->get_vps_ipmap();
		$totals = $this->get_vps_iptables_traffic($ips);
		$module = $useAll === true ? 'quickservers' : 'vps';
		if (sizeof($totals) > 0) {
			//print_r($ips);print_r($totals);
			$cmd = 'curl --connect-timeout 30 --max-time 60 -k -d module='.$module.' -d action=bandwidth -d servers="'.urlencode(base64_encode(gzcompress(json_encode($ips)))).'" -d bandwidth="'.urlencode(base64_encode(gzcompress(json_encode($totals)))).'" "'.$url.'" 2>/dev/null;';
			//echo "CMD: $cmd\n";
			echo trim(`$cmd`);
		}
	}

	public function validIp($ip, $support_ipv6 = false)
	{
		if (version_compare(PHP_VERSION, '5.2.0') >= 0) {
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
				if ($support_ipv6 === false || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)
					return false;
		} else {
			if (!preg_match("/^[0-9\.]{7,15}$/", $ip))
				return false;
			$quads = explode('.', $ip);
			$numquads = count($quads);
			if ($numquads != 4)
				return false;
			for ($i = 0; $i < 4; $i++)
				if ($quads[$i] > 255)
					return false;
		}
		return true;
	}

	public function get_vps_ipmap()
	{
		global $vpsName2Veid, $vpsVeid2Name;
		$vpsName2Veid = array();
		$vpsVeid2Name = array();
		$dir = Vps::$base;
		$vzctl = trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);
		if ($vzctl == ''  && (file_exists('/etc/dhcpd.vps') || file_exists('/etc/dhcp/dhcpd.vps'))) {
			$output = trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; if [ -e /etc/dhcp/dhcpd.vps ]; then DHCPVPS=/etc/dhcp/dhcpd.vps; else DHCPVPS=/etc/dhcpd.vps; fi;  if [ -e \$DHCPVPS ]; then grep "^host" \$DHCPVPS | tr \; " " | awk '{ print $2 " " $8 }'; fi;`);
		} elseif (file_exists('/usr/bin/prlctl')) {
			$output = '';
			foreach (glob('/etc/vz/conf/*.conf') as $file) {
				$txt = file_get_contents($file);
				if (preg_match('/^IP_ADDRESS="([^"]*)"$/mU', $txt, $matches)) {
					$ip = str_replace('/255.255.255.0','', $matches[1]);
					$veid = basename($file, '.conf');
					if (preg_match('/^VEID="([^"]*)"$/mU', $txt, $matches2)) {
						$veid = $matches2[1];
					}
					if (preg_match('/^NAME="([^"]*)"$/mU', $txt, $matches2)) {
						$vpsName2Veid[$matches2[1]] = $veid;
						$vpsVeid2Name[$veid] = $matches2[1];
						$veid = $matches2[1];
					} else {
						$vpsName2Veid[$veid] = $veid;
						$vpsVeid2Name[$veid] = $veid;
					}
					$output .= $veid.' '.$ip.PHP_EOL;
				}
			}
			//$cmd = 'grep -H "^IP_ADDRESS" /etc/vz/conf/[0-9a-z-]*.conf 2>/dev/null | grep -v -e "^#" | sed -e s#"^.*/\([0-9a-z-]*\)\.conf:IP_ADDRESS=\"\([-0-9\. :a-f\/]*\)\""#"\1 \2"#g -e s#"/255.255.255.0"#""#g -e s#" *$"#""#g';
			//$output = rtrim(`$cmd`);
		} else {
			$output = rtrim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzlist -H -o veid,ip 2>/dev/null`);
		}
		$lines = explode("\n", $output);
		$ips = array();
		foreach ($lines as $line) {
			$parts = explode(' ', trim($line));
			if (sizeof($parts) > 1) {
				$id = $parts[0];
				$ip = $parts[1];
				if ($this->validIp($ip) == true) {
					$extra = trim(`touch {$dir}/vps.ipmap ; export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";grep "^$ip:" {$dir}/vps.ipmap | cut -d: -f2`);
					if ($extra != '') {
						$parts = array_merge($parts, explode("\n", $extra));
					}
					for ($x = 1; $x < sizeof($parts); $x++) {
						if ($parts[$x] != '-') {
							$ips[$parts[$x]] = $id;
						}
					}
				}
			}
		}
		return $ips;
	}

	public function vps_iptables_traffic_rules($ips)
	{
		$vzctl = trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);
		$cmd = 'export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/sbin:/usr/sbin"; ';
		foreach ($ips as $ip => $id) {
			if ($this->validIp($ip, false) == true) {
				if ($vzctl == '') {
					$cmd .= "ebtables -t filter -D FORWARD -p IPv4 --ip-dst $ip 2>/dev/null; ";
					$cmd .= "ebtables -t filter -D FORWARD -p IPv4 --ip-src $ip 2>/dev/null; ";
					// run it twice to be safe
					$cmd .= "ebtables -t filter -D FORWARD -p IPv4 --ip-dst $ip 2>/dev/null; ";
					$cmd .= "ebtables -t filter -D FORWARD -p IPv4 --ip-src $ip 2>/dev/null; ";
					$cmd .= "ebtables -t filter -A FORWARD -p IPv4 --ip-dst $ip -c 0 0; ";
					$cmd .= "ebtables -t filter -A FORWARD -p IPv4 --ip-src $ip -c 0 0; ";
				} else {
					$cmd .= "iptables -D FORWARD -d $ip 2>/dev/null; ";
					$cmd .= "iptables -D FORWARD -s $ip 2>/dev/null; ";
					// run it twice to be safe
					$cmd .= "iptables -D FORWARD -d $ip 2>/dev/null; ";
					$cmd .= "iptables -D FORWARD -s $ip 2>/dev/null; ";
					$cmd .= "iptables -A FORWARD -d $ip; ";
					$cmd .= "iptables -A FORWARD -s $ip; ";
				}
			}
		}
		`$cmd`;
	}

	public function get_vps_iptables_traffic($ips)
	{
		$vzctl = trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);
		$totals = array();
		if ($vzctl == '') {
			if (file_exists(('/root/.traffic.last'))) {
				$last = json_decode(file_get_contents('/root/.traffic.last'), true);
				if (is_null($last) || $last === false)
					$last = unserialize(file_get_contents('/root/.traffic.last'));
			}
			$vnetcounters = trim(`grep vnet /proc/net/dev | tr : " " | awk '{ print $1 " " $2 " " $10 }'`);
			if ($vnetcounters != '') {
				$vnetcounters = explode("\n", $vnetcounters);
				$vnets = array();
				foreach ($vnetcounters as $line) {
					list($vnet, $out, $in) = explode(' ', $line);
					//echo "Got    VNet:$vnet   IN:$in    OUT:$out\n";
					$vnets[$vnet] = array('in' => intval($in), 'out' => intval($out));
				}
				$cmd = 'grep -H -i fe /sys/devices/virtual/net/vnet*/address 2>/dev/null| sed s#"/sys/devices/virtual/net/\([^/]*\)/address:fe:\(.*\)$"#"\1 52:\2"#g';
				$vnetmacs = trim(`$cmd`);
				if ($vnetmacs != '') {
					$vnetmacs = explode("\n", $vnetmacs);
					$macs = array();
					foreach ($vnetmacs as $line) {
						list($vnet, $mac) = explode(' ', $line);
						$mac = preg_replace('/^52:16:3e:/', '00:16:3e:', $mac);
						//echo "Got  VNet:$vnet   Mac:$mac\n";
						$vnets[$vnet]['mac'] = $mac;
						$macs[$mac] = $vnet;
					}
					$cmd = 'if [ -e /etc/dhcp/dhcpd.vps ]; then cat /etc/dhcp/dhcpd.vps; else cat /etc/dhcpd.vps; fi | grep ethernet | sed s#"^host *\([a-z0-9\.]*\) *{ *hardware *ethernet *\([^ ;]*\); *fixed-address *\([0-9\.]*\); *} *$"#"\2 \1 \3"#g';
					$macvps = explode("\n", trim(`$cmd`));
					$vpss = array();
					foreach ($macvps as $line) {
						list($mac, $vps, $ip) = explode(' ', $line);
						//echo "Got  Mac:$mac   VPS:$vps   IP:$ip\n";
						if (isset($macs[$mac]) && isset($vnets[$macs[$mac]])) {
							$vpss[$vps] = $vnets[$macs[$mac]];
							$vpss[$vps]['ip'] = $ip;
							if (isset($last) && isset($vpss[$vps])) {
								$in_new = $vpss[$vps]['in'] - intval($last[$vps]['in']);
								$out_new = $vpss[$vps]['out'] - intval($last[$vps]['out']);
							} elseif (isset($last)) {
								$in_new = intval($last[$vps]['in']);
								$out_new = intval($last[$vps]['out']);
							} else {
								$in_new = $vpss[$vps]['in'];
								$out_new = $vpss[$vps]['out'];
							}
							if ($in_new > 0 || $out_new > 0) {
								$totals[$ip] = array('in' => $in_new, 'out' => $out_new);
							}
						}
					}
					if (sizeof($totals) > 0) {
						file_put_contents('/root/.traffic.last', json_encode($vpss));
					}
				}
			}
		} elseif (file_exists('/usr/bin/prlctl')) {
			global $vpsName2Veid, $vpsVeid2Name;
			if (file_exists(('/root/.traffic.last'))) {
				$last = json_decode(file_get_contents('/root/.traffic.last'), true);
				if (is_null($last) || $last === false)
					$last = unserialize(file_get_contents('/root/.traffic.last'));
			}
			preg_match_all('/^(?P<uuid>\S+)\s+(?P<class>\d+)\s+(?P<in_bytes>\d+)\s+(?P<in_pkts>\d+)\s+(?P<out_bytes>\d+)\s+(?P<out_pkts>\d+)$/msuU', trim(`vznetstat -c 1`), $matches);
			$vpss = array();
			foreach ($matches['uuid'] as $idx => $uuid) {
				if ($uuid != '0') {
					$in = intval($matches['in_bytes'][$idx]);
					$out = intval($matches['out_bytes'][$idx]);
					if ((false !== $ip = array_search($uuid, $ips))
					|| (array_key_exists($uuid, $vpsName2Veid) && false !== $ip = array_search($vpsName2Veid[$uuid], $ips))
					|| (array_key_exists($uuid, $vpsVeid2Name) && false !== $ip = array_search($vpsVeid2Name[$uuid], $ips))) {
						if (isset($last[$ip]))
							list($in_last, $out_last) = $last[$ip];
						else
							list($in_last, $out_last) = array(0,0);
						$vpss[$ip] = array($in, $out);
						$in = $in - intval($in_last);
						$out = $out - intval($out_last);
						$total = $in + $out;
						if ($total > 0) {
							$totals[$ip] = array('in' => $in, 'out' => $out);
						}
					}
				}
			}
			/* foreach ($ips as $ip => $id) {
			if ($this->validIp($ip, false) == true) {
			$veid = $vpsName2Veid[$id];
			$line = explode(' ', trim(`vznetstat -c 1 -v "{$veid}"|tail -n 1|awk '{ print \$3 " " \$5 }'`));
			list($in, $out) = $line;
			if (isset($last[$ip]))
			list($in_last, $out_last) = $last[$ip];
			else
			list($in_last, $out_last) = array(0,0);
			$vpss[$ip] = array(intval($in), intval($out));
			$in = intval($in) - intval($in_last);
			$out = intval($out) - intval($out_last);
			$total = $in + $out;
			if ($total > 0) {
			$totals[$ip] = array('in' => $in, 'out' => $out);
			}
			}
			} */
			if (sizeof($totals) > 0) {
				file_put_contents('/root/.traffic.last', json_encode($vpss));
			}
		} else {
			foreach ($ips as $ip => $id) {
				if ($this->validIp($ip, false) == true) {
					$lines = explode("\n", trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; iptables -nvx -L FORWARD 2>/dev/null | grep -v DROP  | awk '{ print " " $7 " " $8 " " $2 }' | grep -vi "[a-z]" | sort -n | grep " $ip " | awk '{ print \$3 }'`));
					//echo "$ip:$id:$lines\n";
					if (sizeof($lines) == 2) {
						list($in, $out) = $lines;
						$total = intval($in) + intval($out);
						if ($total > 0) {
							$totals[$ip] = array('in' => intval($in), 'out' => intval($out));
						}
					}
				}
			}
			`PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/sbin:/usr/sbin"  iptables -Z`;
			$this->vps_iptables_traffic_rules($ips);
		}
		return $totals;
	}

}
