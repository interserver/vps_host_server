#!/usr/bin/env php
<?php
/**
 * QuickServer Functionality
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2018
 * @package MyAdmin
 * @category QuickServer
 */

function validIp($ip, $display_errors = true, $support_ipv6 = false)
{
	if (version_compare(PHP_VERSION, '5.2.0') >= 0) {
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
			if ($support_ipv6 === false || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
				return false;
			}
		}
	} else {
		if (!preg_match("/^[0-9\.]{7,15}$/", $ip)) {
			// don't display errors cuz this gets called w/ a blank entry when people didn't even submit anything yet
			//add_output('<font class="error">IP '.$ip.' Too short/long</font>');
			return false;
		}
		$quads = explode('.', $ip);
		$numquads = count($quads);
		if ($numquads != 4) {
			if ($display_errors) {
				error_log('<font class="error">IP '.$ip.' Too many quads</font>');
			}
			return false;
		}
		for ($i = 0; $i < 4; $i++) {
			if ($quads[$i] > 255) {
				if ($display_errors) {
					error_log('<font class="error">IP '.$ip.' number '.$quads[$i].' too high</font>');
				}
				return false;
			}
		}
	}
	return true;
}

function get_qs_ipmap()
{
	$dir = __DIR__;
	$vzctl = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);
	if ($vzctl == ''  && (file_exists('/etc/dhcpd.vps') || file_exists('/etc/dhcp/dhcpd.vps'))) {
		$output = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; if [ -e /etc/dhcp/dhcpd.vps ]; then DHCPVPS=/etc/dhcp/dhcpd.vps; else DHCPVPS=/etc/dhcpd.vps; fi; if [ -e \$DHCPVPS ]; then grep "^host" \$DHCPVPS | tr \; " " | awk '{ print $2 " " $8 }'; fi;`);
	} elseif (file_exists('/usr/bin/prlctl')) {
		$output = '';
		foreach (glob('/etc/vz/conf/*.conf') as $file) {
			$txt = file_get_contents($file);
			if (preg_match('/^IP_ADDRESS="([^"]*)"$/mU', $txt, $matches)) {
				$ip = str_replace('/255.255.255.0','', $matches[1]);
				$veid = basename($file, '.conf');
				if (preg_match('/^UUID="([^"]*)"$/mU', $txt, $matches2)) {
					$veid = $matches2[1];
				}
				$output .= $veid.' '.$ip.PHP_EOL;
			}
		}
		//$cmd = 'grep -H "^IP_ADDRESS" /etc/vz/conf/[0-9a-z-]*.conf 2>/dev/null | grep -v -e "^#" | sed -e s#"^.*/\([0-9a-z-]*\)\.conf:IP_ADDRESS=\"\([-0-9\. :a-f\/]*\)\""#"\1 \2"#g -e s#"/255.255.255.0"#""#g -e s#" *$"#""#g';
		//$output = rtrim(`$cmd`);
	} else {
		$output = rtrim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzlist -H -o veid,ip 2>/dev/null`);
	}
	$lines = explode("\n", $output);
	$ips = array();
	foreach ($lines as $line) {
		$parts = explode(' ', trim($line));
		if (sizeof($parts) > 1) {
			$id = $parts[0];
			$ip = $parts[1];
			if (validIp($ip, false) == true) {
				$extra = trim(`touch {$dir}/vps.ipmap ; export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";grep "^$ip:" {$dir}/vps.ipmap | cut -d: -f2`);
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

function qs_iptables_traffic_rules($ips)
{
	$vzctl = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);
	$cmd = 'export PATH="$PATH:/sbin:/usr/sbin"; ';
	foreach ($ips as $ip => $id) {
		if (validIp($ip, false) == true) {
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

function get_qs_iptables_traffic($ips)
{
	$vzctl = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);
	$totals = array();
	foreach ($ips as $ip => $id) {
		if ($vzctl == '') {
			$lines = explode("\n", trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; ebtables -L --Lc --Lx | grep " $ip -j CONTINUE -c " |  sed s#"ebtables -t filter -A FORWARD -p IPv4 --ip-... $ip -j CONTINUE -c "#""#g | cut -d" " -f2`));
		} else {
			$lines = explode("\n", trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; iptables -nvx -L FORWARD 2>/dev/null | awk '{ print " " $7 " " $8 " " $2 }' | grep -vi "[a-z]" | sort -n | grep " $ip " | awk '{ print $3 }'`));
		}
		if (sizeof($lines) == 2) {
			list($in, $out) = $lines;
			//echo "$ip $in $out\n";
			$total = $in + $out;
//			$total = intval(trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; iptables -nvx -L FORWARD 2>/dev/null | grep " $ip " | tr -s [:blank:] |cut -d' ' -f3| awk '{ sum += $1 } END { print sum; }'`));
			if ($total > 0) {
				$totals[$ip] = array('in' => $in, 'out' => $out);
			}
			//echo "$ip = " . $totals[$ip].PHP_EOL;
		}
	}
	if ($vzctl == '') {
		`PATH="\$PATH:/sbin:/usr/sbin"  ebtables -Z`;
	} else {
		`PATH="\$PATH:/sbin:/usr/sbin"  iptables -Z`;
	}
	qs_iptables_traffic_rules($ips);
	return $totals;
}

//$url = 'https://mynew.interserver.net/qs_queue.php';
$url = 'http://mynew.interserver.net:55151/queue.php';
//if (file_exists('/usr/sbin/vzctl'))
//{
	$ips = get_qs_ipmap();
	$totals = get_qs_iptables_traffic($ips);
	//print_r($totals);
	$cmd = 'curl --connect-timeout 30 --max-time 60 -k -d module=quickservers -d action=bandwidth -d servers="'.urlencode(base64_encode(gzcompress(json_encode($ips)))).'" -d bandwidth="'.urlencode(base64_encode(gzcompress(json_encode($totals)))).'" "'.$url.'" 2>/dev/null;';
	//echo "CMD: $cmd\n";
	echo trim(`$cmd`);
//}
