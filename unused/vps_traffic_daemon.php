#!/usr/bin/env php
<?php

$pid = getmypid();
$oldpid = trim(`ps aux  | grep '/usr/bin/env php ./vps_traffic.php' | grep -v grep | awk '{ print $2 }' | grep -v $pid`);
if ($oldpid != '') {
	if (time() - trim(file_get_contents(__DIR__.'/vps_traffic.pid')) > (5 * 60)) {
		`kill -9 "$oldpid";`;
	} else {
		exit;
	}
}
$starttime = time();
$updateinterval = 5 * 60;
$lastupdate = 0;
$GLOBALS['time'] = time();
$oldips = array();
$vzctl = trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);

function get_vps_ipmap()
{
	$dir = __DIR__;
	if ($GLOBALS['vzctl'] == '') {
		$output = trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; if [ -e /etc/dhcp/dhcpd.vps ]; then DHCPVPS=/etc/dhcp/dhcpd.vps; else DHCPVPS=/etc/dhcpd.vps; fi;  if [ -e \$DHCPVPS ]; then grep "^host" \$DHCPVPS | tr \; " " | awk '{ print $2 " " $8 }'; fi;`);
	} else {
		$output = rtrim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzlist -H -o veid,ip`);
	}
	$lines = explode("\n", $output);
	$ips = array();
	foreach ($lines as $line) {
		$parts = explode(' ', trim($line));
		$id = $parts[0];
		$ip = $parts[1];
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
	return $ips;
}

function vps_iptables_traffic_rules($ips)
{
	$cmd = 'export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/sbin:/usr/sbin"; ';
	foreach ($ips as $ip => $id) {
		if ($GLOBALS['vzctl'] == '') {
			$cmd .= "ebtables -t filter -D FORWARD -p IPv4 --ip-dst $ip 2>/dev/null; ";
			$cmd .= "ebtables -t filter -D FORWARD -p IPv4 --ip-src $ip 2>/dev/null; ";
		} else {
			$cmd .= "iptables -D FORWARD -d $ip 2>/dev/null; ";
			$cmd .= "iptables -D FORWARD -s $ip 2>/dev/null; ";
		}
	}
	foreach ($ips as $ip => $id) {
		if ($GLOBALS['vzctl'] == '') {
			$cmd .= "ebtables -t filter -A FORWARD -p IPv4 --ip-dst $ip -c 0 0; ";
			$cmd .= "ebtables -t filter -A FORWARD -p IPv4 --ip-src $ip -c 0 0; ";
		} else {
			$cmd .= "iptables -A FORWARD -d $ip; ";
			$cmd .= "iptables -A FORWARD -s $ip; ";
		}
	}
	trim(`$cmd`);
}

function get_vps_iptables_traffic($ips)
{
	$totals = array();
	if ($GLOBALS['vzctl'] == '') {
		$lines = explode("\n", trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; ebtables -L -Z --Lc --Lx | grep " -j CONTINUE -c " |  sed s#"ebtables -t filter -A FORWARD -p IPv4 --ip-"#""#g | sed s#"-j CONTINUE -c "#""#g`));
//			print_r($lines);
		foreach ($lines as $line) {
			$parts = explode(' ', trim($line));
			$ip = $parts[1];
			if ($parts[0] == 'src') {
				$field = 'out';
			} else {
				$field = 'in';
			}
			if (isset($ips[$ip])) {
				$totals[$ip][$field] = $parts[3];
			}
		}
	} else {
		$lines = explode("\n", trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; iptables -nvx -L -Z FORWARD 2>/dev/null | grep -v DROP | awk '{ print " " $7 " " $8 " " $2 }' | grep -vi "[a-z]"`));
		foreach ($lines as $line) {
			$parts = explode(' ', trim($line));
			if ($parts[0] == '0.0.0.0/0') {
				$ip = $parts[1];
				$field = 'in';
			} else {
				$ip = $parts[0];
				$field = 'out';
			}
			if (isset($ips[$ip])) {
				$totals[$ip][$field] = $parts[2];
			}
		}
	}
	if ($ips != $GLOBALS['oldips']) {
		vps_iptables_traffic_rules($ips);
		$GLOBALS['oldips'] = $ips;
//		echo "creating rules again took " . (time() - $GLOBALS['time']) . " seconds\n"; $GLOBALS['time'] = time();
	}
	return $totals;
}

//$url = 'https://mynew.interserver.net/vps_queue.php';
$url = 'http://mynew.interserver.net:55151/queue.php';
//if (file_exists('/usr/sbin/vzctl'))
//{
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, 59);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
$exit = false;
while (!$exit) {
	$fd = fopen(__DIR__.'/vps_traffic.pid', 'w');
	fputs($fd, time());
	fclose($fd);
	if (time() - $lastupdate > $updateinterval) {
		echo 'ipmap';
		$ips = get_vps_ipmap();
		$lastupdate = time();
//		echo "get_vps_ipmap() took " . (time() - $GLOBALS['time']) . " seconds\n"; $GLOBALS['time'] = time();
	}
	$totals = get_vps_iptables_traffic($ips);
//	echo "get_vps_iptables_traffic() took " . (time() - $GLOBALS['time']) . " seconds\n"; $GLOBALS['time'] = time();
	//print_r($totals);
	$fieldstring = "module=vps&action=bandwidth&servers=" . urlencode(base64_encode(gzcompress(json_encode($ips)))) . "&bandwidth=" . urlencode(base64_encode(gzcompress(json_encode($totals))));
	curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldstring);
	$retval = curl_exec($ch);
	if (curl_errno($ch)) {
		$retval = "CURL Error: ".curl_errno($ch)." - ".curl_error($ch);
		echo "Curl Error $retval\n";
	}
	echo ".";
//	echo "sending the data took " . (time() - $GLOBALS['time']) . " seconds\n"; $GLOBALS['time'] = time();
	sleep(1);
//	echo "finished sleeping\n";
//}
}
curl_close($ch);
