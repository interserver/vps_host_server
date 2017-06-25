#!/usr/bin/php -q
<?php
/**
 * VPS Functionality
 * Last Changed: $LastChangedDate$
 * @author $Author$
 * @version $Revision$
 * @copyright 2017
 * @package MyAdmin
 * @category VPS
 */

function validIp($ip, $display_errors = true, $support_ipv6 = false) {
	if (version_compare(PHP_VERSION, '5.2.0') >= 0)
	{
		if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
			if ($support_ipv6 === false || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)
				return false;
	}
	else
	{
		if (!preg_match("/^[0-9\.]{7,15}$/", $ip))
		{
			// don't display errors cuz this gets called w/ a blank entry when people didn't even submit anything yet
			//add_output('<font class="error">IP '.$ip.' Too short/long</font>');
			return false;
		}
		$quads = explode('.', $ip);
		$numquads = count($quads);
		if ($numquads != 4)
		{
			if ($display_errors)
				error_log('<font class="error">IP '.$ip.' Too many quads</font>');
			return false;
		}
		for ($i = 0; $i < 4; $i++)
			if ($quads[$i] > 255)
			{
				if ($display_errors)
					error_log('<font class="error">IP '.$ip.' number '.$quads[$i].' too high</font>');
				return false;
			}
	}
	return true;
}

function get_vps_ipmap() {
	$vzctl = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);
	if ($vzctl == '')
		$output = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; if [ -e /etc/dhcp/dhcpd.vps ]; then DHCPVPS=/etc/dhcp/dhcpd.vps; else DHCPVPS=/etc/dhcpd.vps; fi;  grep "^host" \$DHCPVPS | tr \; " " | awk '{ print $2 " " $8 }'`);
	else
		$output = rtrim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzlist -H -o veid,ip 2>/dev/null`);
	$lines = explode("\n", $output);
	$ips = array();
	foreach ($lines as $line)
	{
		$parts = explode(' ', trim($line));
		if (sizeof($parts) > 1)
		{
			$id = $parts[0];
			$ip = $parts[1];
			if (validIp($ip, false) == true)
			{
				$extra = trim(`touch /root/cpaneldirect/vps.ipmap ; export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";grep "^$ip:" /root/cpaneldirect/vps.ipmap | cut -d: -f2`);
				if ($extra != '')
					$parts = array_merge($parts, explode("\n", $extra));
				for ($x = 1; $x < sizeof($parts); $x++)
					if ($parts[$x] != '-')
						$ips[$parts[$x]] = $id;
			}
		}
	}
	return $ips;
}

function vps_iptables_traffic_rules($ips) {
	$vzctl = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);
	$cmd = 'export PATH="$PATH:/sbin:/usr/sbin"; ';
	foreach ($ips as $ip => $id)
	{
		if (validIp($ip, false) == true)
		{
			$cmd .= "iptables -D FORWARD -d $ip 2>/dev/null; ";
			$cmd .= "iptables -D FORWARD -s $ip 2>/dev/null; ";
			// run it twice to be safe
			$cmd .= "iptables -D FORWARD -d $ip 2>/dev/null; ";
			$cmd .= "iptables -D FORWARD -s $ip 2>/dev/null; ";
			$cmd .= "iptables -A FORWARD -d $ip; ";
			$cmd .= "iptables -A FORWARD -s $ip; ";
		}
	}
	`$cmd`;
}

function get_vps_iptables_traffic($ips) {
	$vzctl = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; which vzctl 2>/dev/null;`);
	$totals = array();
	if ($vzctl == '')
	{
		if (file_exists(('/root/.traffic.last')))
			$last = unserialize(file_get_contents('/root/.traffic.last'));
		$vnetcounters = trim(`grep vnet /proc/net/dev | tr : " " | awk '{ print $1 " " $2 " " $10 }'`);
		if ($vnetcounters != '')
		{
			$vnetcounters = explode("\n", $vnetcounters);
			$vnets = array();
			foreach ($vnetcounters as $line)
			{
				list($vnet, $out, $in) = explode(' ', $line);
				//echo "Got    VNet:$vnet   IN:$in    OUT:$out\n";
				$vnets[$vnet] = array('in' => $in, 'out' => $out);
			}
			$cmd = 'grep -H -i fe /sys/devices/virtual/net/vnet*/address 2>/dev/null| sed s#"/sys/devices/virtual/net/\([^/]*\)/address:fe:\(.*\)$"#"\1 52:\2"#g';
			$vnetmacs = trim(`$cmd`);
			if ($vnetmacs != '')
			{
				$vnetmacs = explode("\n", $vnetmacs);
				$macs = array();
				foreach ($vnetmacs as $line)
				{
					list($vnet, $mac) = explode(' ', $line);
					//echo "Got  VNet:$vnet   Mac:$mac\n";
					$vnets[$vnet]['mac'] = $mac;
					$macs[$mac] = $vnet;
				}
				$cmd = 'if [ -e /etc/dhcp/dhcpd.vps ]; then cat /etc/dhcp/dhcpd.vps; else cat /etc/dhcpd.vps; fi | grep ethernet | sed s#"^host \([a-z0-9\.]*\) { hardware ethernet \([^;]*\); fixed-address \([0-9\.]*\);}$"#"\2 \1 \3"#g';
				$macvps = explode("\n", trim(`$cmd`));
				$vpss = array();
				foreach ($macvps as $line)
				{
					list($mac, $vps, $ip) = explode(' ', $line);
					//echo "Got  Mac:$mac   VPS:$vps   IP:$ip\n";
					if (isset($macs[$mac]) && isset($vnets[$macs[$mac]]))
					{
						$vpss[$vps] = $vnets[$macs[$mac]];
						$vpss[$vps]['ip'] = $ip;
						if (isset($last) && isset($vpss[$vps]))
						{
							$in_new = bcsub($vpss[$vps]['in'], $last[$vps]['in'], 0);
							$out_new = bcsub($vpss[$vps]['out'], $last[$vps]['out'], 0);
						}
						elseif (isset($last))
						{
							$in_new = $last[$vps]['in'];
							$out_new = $last[$vps]['out'];
						}
						else
						{
							$in_new = $vpss[$vps]['in'];
							$out_new = $vpss[$vps]['out'];
						}
						if ($in_new > 0 || $out_new > 0)
							$totals[$ip] = array('in' => $in_new, 'out' => $out_new);
					}
				}
				if (sizeof($totals) > 0)
					file_put_contents('/root/.traffic.last', serialize($vpss));
			}
		}
	}
	else
	{
		foreach ($ips as $ip => $id)
		{
			if (validIp($ip, false) == true)
			{
				$lines = explode("\n", trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; iptables -nvx -L FORWARD 2>/dev/null | grep -v DROP  | awk '{ print " " $7 " " $8 " " $2 }' | grep -vi "[a-z]" | sort -n | grep " $ip " | awk '{ print $3 }'`));
				if (sizeof($lines) == 2)
				{
					list($in,$out) = $lines;
					$total = $in + $out;
					if ($total > 0)
						$totals[$ip] = array('in' => $in, 'out' => $out);
				}
			}
		}
		`PATH="\$PATH:/sbin:/usr/sbin"  iptables -Z`;
		vps_iptables_traffic_rules($ips);
	}
	return $totals;
}

$url = 'https://myvps2.interserver.net/vps_queue.php';
$ips = get_vps_ipmap();
$totals = get_vps_iptables_traffic($ips);
if (sizeof($totals) > 0)
{
	//print_r($totals);
	$cmd = 'curl --connect-timeout 60 --max-time 600 -k -d action=bandwidth -d servers="'.urlencode(base64_encode(gzcompress(serialize($ips)))).'" -d bandwidth="'.urlencode(base64_encode(gzcompress(serialize($totals)))).'" "'.$url.'" 2>/dev/null;';
	//echo "CMD: $cmd\n";
	echo trim(`$cmd`);
}
