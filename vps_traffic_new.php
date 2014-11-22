#!/usr/bin/php -q 
<?php
/**
 * VPS Functionality
 * Last Changed: $LastChangedDate$
 * @author $Author$
 * @version $Revision$
 * @copyright 2012
 * @package MyAdmin
 * @category VPS
 */
function get_vps_ipmap()
{
	$vzctl = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);
	if ($vzctl == '')
	{
		$output = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; if [ -e /etc/dhcp/dhcpd.vps ]; then DHCPVPS=/etc/dhcp/dhcpd.vps; else DHCPVPS=/etc/dhcpd.vps; fi;  grep "^host" \$DHCPVPS | tr \; " " | awk '{ print $2 " " $8 }'`);
	}
	else
	{
		$output = rtrim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzlist -H -o veid,ip 2>/dev/null`);
	}
	$lines = explode("\n", $output);
	$ips = array();
	foreach ($lines as $line)
	{
		$parts = explode(' ', trim($line));
		if (sizeof($parts) > 1)
		{
			$id = $parts[0];
			$ip = $parts[1];
			$extra = trim(`touch /root/cpaneldirect/vps.ipmap ; export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";grep "^$ip:" /root/cpaneldirect/vps.ipmap | cut -d: -f2`);
			if ($extra != '')
			{
				$parts = array_merge($parts, explode("\n", $extra));
			}
			for ($x = 1; $x < sizeof($parts); $x++)
			{
				if ($parts[$x] != '-')
				{
					$ips[$parts[$x]] = $id;
				}
			}
		}
	}
	return $ips;
}

function vps_iptables_traffic_rules($ips)
{
	$vzctl = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);
	$cmd = 'export PATH="$PATH:/sbin:/usr/sbin"; ';
	foreach ($ips as $ip => $id)
	{
		if ($vzctl == '')
		{
			$cmd .= "ebtables -t filter -D FORWARD -p IPv4 --ip-dst $ip 2>/dev/null; ";
			$cmd .= "ebtables -t filter -D FORWARD -p IPv4 --ip-src $ip 2>/dev/null; ";
			// run it twice to be safe
			$cmd .= "ebtables -t filter -D FORWARD -p IPv4 --ip-dst $ip 2>/dev/null; ";
			$cmd .= "ebtables -t filter -D FORWARD -p IPv4 --ip-src $ip 2>/dev/null; ";
		}
		else
		{
			$cmd .= "iptables -D FORWARD -d $ip 2>/dev/null; ";
			$cmd .= "iptables -D FORWARD -s $ip 2>/dev/null; ";
			// run it twice to be safe
			$cmd .= "iptables -D FORWARD -d $ip 2>/dev/null; ";
			$cmd .= "iptables -D FORWARD -s $ip 2>/dev/null; ";
		}
	}
	foreach ($ips as $ip => $id)
	{
		if ($vzctl == '')
		{
			$cmd .= "ebtables -t filter -A FORWARD -p IPv4 --ip-dst $ip -c 0 0; ";
			$cmd .= "ebtables -t filter -A FORWARD -p IPv4 --ip-src $ip -c 0 0; ";
		}
		else
		{
			$cmd .= "iptables -A FORWARD -d $ip; ";
			$cmd .= "iptables -A FORWARD -s $ip; ";
		}
	}
	`$cmd`;
}

function get_vps_iptables_traffic($ips)
{
	$vzctl = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);
	$totals = array();
	if ($vzctl == '')
	{
		$vnetcounters = explode("\n", trim(`grep vnet /proc/net/dev | awk '{ print $1 $2 " " $10}' | tr : " "`));
		$vnets = array();
		foreach ($vnetcounters as $line)
		{
			list($vnet, $in, $out) = explode(' ', $line);
			$vnets[$vnet] = array('in' => $in, 'out' => $out);
		}
		$vnetmacs = explode("\n", trim(`grep -i fe /sys/devices/virtual/net/vnet*/address | sed s#"/sys/devices/virtual/net/\([^/]*\)/address:fe:\(.*\)$"#"\1 52:\2"#g`));
		$macs = array();
		foreach ($vnetmacs as $line)
		{
			list($vnet, $mac) = explode(' ', $line);
			$vnets[$vnet]['mac'] = $mac;
			$macs[$mac] = $vnet;
		}
		$macvps = explode("\n", trim(`if [ -e /etc/dhcp/dhcpd.vps ]; then cat /etc/dhcp/dhcpd.vps; else cat /etc/dhcpd.vps; fi | grep ethernet | sed s#"^host \([a-z0-9\.]*\) { hardware ethernet \([^;]*\); fixed-address \([0-9\.]*\);}$"#"\2 \1 \3"#g`));
		$totals = array();
		foreach ($macvps as $line)
		{
			list($mac, $vps, $ip) = explode(' ', $line);
			$totals[$vps] = $vnets[$macs[$mac]];
			$totals[$vps]['ip'] = $ip;
		}
	}
	else
	{
		foreach ($ips as $ip => $id)
		{
			$lines = explode("\n", trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; iptables -nvx -L FORWARD 2>/dev/null | grep -v DROP  | awk '{ print " " $7 " " $8 " " $2 }' | grep -vi "[a-z]" | sort -n | grep " $ip " | awk '{ print $3 }'`));
			if (sizeof($lines) == 2)
			{
				list($in,$out) = $lines;
				$total = $in + $out;
				if ($total > 0)
				{
					$totals[$ip] = array('in' => $in, 'out' => $out);
				}
			}
		}
		`PATH="\$PATH:/sbin:/usr/sbin"  iptables -Z`;
	}
	vps_iptables_traffic_rules($ips);
	return $totals;
}

$url = 'https://myvps2.interserver.net/vps_queue.php';
//if (file_exists('/usr/sbin/vzctl'))
//{
	$ips = get_vps_ipmap();
	$totals = get_vps_iptables_traffic($ips);
	//print_r($totals);
	$cmd = 'curl --connect-timeout 60 --max-time 240 -k -d action=bandwidth -d servers="' . urlencode(base64_encode(gzcompress(serialize($ips)))) . '" -d bandwidth="' . urlencode(base64_encode(gzcompress(serialize($totals)))) . '" "' . $url . '" 2>/dev/null;';
	//echo "CMD: $cmd\n";
	echo trim(`$cmd`);
//}
?>
