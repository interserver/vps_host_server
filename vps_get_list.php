#!/usr/bin/php -q
<?php

require_once(dirname(__FILE__) . '/xml2array.php');

/**
 * get_vps_list()
 *
 * @return
 */
function get_vps_list() {
	$url = 'https://myvps2.interserver.net/vps_queue.php';
	$curl_cmd = '';
	$servers = array();
	if (!file_exists('/usr/sbin/vzctl'))
	{
		$cmd = 'export PATH="$PATH:/bin:/usr/bin:/sbin:/usr/sbin";virsh list --all | grep -v -e "State$" -e "------$" -e "^$" | awk "{ print \$2 \" \" \$3 }"';
		//echo "Running $cmd\n";
		$out = trim(`$cmd`);
		$lines = explode("\n", $out);
		$cmd = '';
		foreach ($lines as $serverline)
		{
			if (trim($serverline) != '')
			{
				$parts = explode(' ', $serverline);
				$name = $parts[0];
				$veid = str_replace(array('windows', 'linux'), array('', ''), $name);
				$status = $parts[1];
				$out = `export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";virsh dumpxml $name`;
				$xml = xml2array($out);
				$server = array(
					'veid' => $veid,
					'status' => $status,
					'hostname' => $name,
					'kmemsize' => $xml['domain']['memory'],
				);
				if (isset($xml['domain']['devices']['interface']))
				{
					if (isset($xml['domain']['devices']['interface']['mac_attr']))
						$server['mac'] = $xml['domain']['devices']['interface']['mac_attr']['address'];
					elseif (isset($xml['domain']['devices']['interface'][0]['mac_attr']))
						$server['mac'] = $xml['domain']['devices']['interface'][0]['mac_attr']['address'];
				}
				if (isset($xml['domain']['devices']['graphics_attr']))
				{
					$server['vnc'] = $xml['domain']['devices']['graphics_attr']['port'];
				}
				if ($status == 'running')
				{
/*
					$disk = trim(`/root/cpaneldirect/vps_kvm_disk_usage.sh $name`);
					if ($disk != '')
					{
						$dparts = explode(':', $disk);
						$server['diskused'] = $dparts[2];
						$server['diskmax'] = $dparts[1];
					}
*/
					if (isset($xml['domain']['devices']['graphics_attr']))
					{
						$port = (integer)$xml['domain']['devices']['graphics_attr']['port'];
						if ($port >= 5900)
						{
// vncsnapshot Encodings: raw copyrect tight hextile zlib corre rre zrle
$cmd .= "./vncsnapshot -dieblank -compresslevel 0 -quality 70 -vncQuality 7 -jpeg -fps 5 -count 1 -quiet -encodings raw :\$(($port - 5900)) shot_{$port}.jpg >/dev/null 2>&1;\n";
//$curl_cmd .= " -F shot".$port."=@shot_".$port.".jpg";
//					rm -f shot*jpg; for port in $(lsof -n|grep LISTEN |grep 127.0.0.1: |cut -d: -f2 | cut -d" " -f1 | sort | uniq); do ./vncsnapshot -dieblank -compresslevel 0 -quality 70 -vncQuality 7 -jpeg -fps 5 -count 1 -quiet -encodings "raw" :$(($port - 5900)) shot_${port}.jpg >/dev/null 2>&1; done;
//echo "Port:" . $xml['domain']['devices']['graphics_attr']['port'] . "\n";
//$vncdisplay = (integer)abs($port - 5900);
//$cmd .= "function shot_${port} { touch shot_${port}.started;/root/cpaneldirect/vncsnapshot -compresslevel 9 -quality 100 -vncQuality 9 -allowblank -count 1 -fps 5 -quiet 127.0.0.1:${vncdisplay} shot_${port}.jpg >/dev/null 2>&1; convert shot_${port}.jpg -quality 75 shot_${port}.gif; rm -f shot_${port}.jpg shot_${port}.started; };\n shot_${port} &\n";
//								$cmd .= "/root/cpaneldirect/vps_kvm_screenshot_new.sh ${port} &\n";
//								$curl_cmd .= " -F shot".$port."=@shot_".$port.".gif";
//$cmd .= "/root/cpaneldirect/vps_kvm_screenshot.sh $vncdisplay '$url?action=screenshot&name=$name' &\n";
						}
					}
				}
				$servers[$veid] = $server;
			}
		}
		if ($cpu_usage = @unserialize(`bash /root/cpaneldirect/cpu_usage.sh -serialize`))
		{
			foreach ($cpu_usage as $id => $cpu_data)
			{
				//$servers[$id]['cpu_usage'] = serialize($cpu_data);
				$servers[$id]['cpu_usage'] = $cpu_data;
			}
		}
		$curl_cmd = '$(for i in shot_*jpg; do if [ "$i" != "shot_*jpg" ]; then p=$(echo $i | cut -c5-9); gzip -9 -f $i; echo -n " -F shot$p=@${i}.gz"; fi; done;)';
//			$cmd .= 'while [ -e "shot_*.started" ]; do sleep 1s; done;'."\n";
		//echo "CMD:$cmd\n";
		echo `$cmd`;
	}
	else
	{
		if (file_exists('/usr/bin/prlctl'))
			$cmd = 'export PATH="$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzlist -a -o uuid,numproc,status,ip,hostname,swappages,layout,kmemsize,kmemsize.f,lockedpages,lockedpages.f,privvmpages,privvmpages.f,shmpages,shmpages.f,numproc.f,physpages,physpages.f,vmguarpages,vmguarpages.f,oomguarpages,oomguarpages.f,numtcpsock,numtcpsock.f,numflock,numflock.f,numpty,numpty.f,numsiginfo,numsiginfo.f,tcpsndbuf,tcpsndbuf.f,tcprcvbuf,tcprcvbuf.f,othersockbuf,othersockbuf.f,dgramrcvbuf,dgramrcvbuf.f,numothersock,numothersock.f,dcachesize,dcachesize.f,numfile,numfile.f,numiptent,numiptent.f,diskspace,diskspace.s,diskspace.h,diskinodes,diskinodes.s,diskinodes.h,laverage -H;';
		else
			$cmd = 'export PATH="$PATH:/bin:/usr/bin:/sbin:/usr/sbin";if [ "$(vzlist -L |grep vswap)" = "" ]; then prlctl list -a -o ctid,numproc,status,ip,hostname,swappages,layout,kmemsize,kmemsize.f,lockedpages,lockedpages.f,privvmpages,privvmpages.f,shmpages,shmpages.f,numproc.f,physpages,physpages.f,vmguarpages,vmguarpages.f,oomguarpages,oomguarpages.f,numtcpsock,numtcpsock.f,numflock,numflock.f,numpty,numpty.f,numsiginfo,numsiginfo.f,tcpsndbuf,tcpsndbuf.f,tcprcvbuf,tcprcvbuf.f,othersockbuf,othersockbuf.f,dgramrcvbuf,dgramrcvbuf.f,numothersock,numothersock.f,dcachesize,dcachesize.f,numfile,numfile.f,numiptent,numiptent.f,diskspace,diskspace.s,diskspace.h,diskinodes,diskinodes.s,diskinodes.h,laverage -H; else vzlist -a -o ctid,numproc,status,ip,hostname,vswap,layout,kmemsize,kmemsize.f,lockedpages,lockedpages.f,privvmpages,privvmpages.f,shmpages,shmpages.f,numproc.f,physpages,physpages.f,vmguarpages,vmguarpages.f,oomguarpages,oomguarpages.f,numtcpsock,numtcpsock.f,numflock,numflock.f,numpty,numpty.f,numsiginfo,numsiginfo.f,tcpsndbuf,tcpsndbuf.f,tcprcvbuf,tcprcvbuf.f,othersockbuf,othersockbuf.f,dgramrcvbuf,dgramrcvbuf.f,numothersock,numothersock.f,dcachesize,dcachesize.f,numfile,numfile.f,numiptent,numiptent.f,diskspace,diskspace.s,diskspace.h,diskinodes,diskinodes.s,diskinodes.h,laverage -H; fi;';
		//echo "Running $cmd\n";
		$out = `$cmd`;
		preg_match_all('/^\s*(?P<ctid>[^\s]+)\s+(?P<numproc>[^\s]+)\s+(?P<status>[^\s]+)\s+(?P<ip>[^\s]+)\s+(?P<hostname>[^\s]+)\s+(?P<vswap>[^\s]+)\s+(?P<layout>[^\s]+)\s+(?P<kmemsize>[^\s]+)\s+(?P<kmemsize_f>[^\s]+)\s+(?P<lockedpages>[^\s]+)\s+(?P<lockedpages_f>[^\s]+)\s+(?P<privvmpages>[^\s]+)\s+(?P<privvmpages_f>[^\s]+)\s+(?P<shmpages>[^\s]+)\s+(?P<shmpages_f>[^\s]+)\s+(?P<numproc_f>[^\s]+)\s+(?P<physpages>[^\s]+)\s+(?P<physpages_f>[^\s]+)\s+(?P<vmguarpages>[^\s]+)\s+(?P<vmguarpages_f>[^\s]+)\s+(?P<oomguarpages>[^\s]+)\s+(?P<oomguarpages_f>[^\s]+)\s+(?P<numtcpsock>[^\s]+)\s+(?P<numtcpsock_f>[^\s]+)\s+(?P<numflock>[^\s]+)\s+(?P<numflock_f>[^\s]+)\s+(?P<numpty>[^\s]+)\s+(?P<numpty_f>[^\s]+)\s+(?P<numsiginfo>[^\s]+)\s+(?P<numsiginfo_f>[^\s]+)\s+(?P<tcpsndbuf>[^\s]+)\s+(?P<tcpsndbuf_f>[^\s]+)\s+(?P<tcprcvbuf>[^\s]+)\s+(?P<tcprcvbuf_f>[^\s]+)\s+(?P<othersockbuf>[^\s]+)\s+(?P<othersockbuf_f>[^\s]+)\s+(?P<dgramrcvbuf>[^\s]+)\s+(?P<dgramrcvbuf_f>[^\s]+)\s+(?P<numothersock>[^\s]+)\s+(?P<numothersock_f>[^\s]+)\s+(?P<dcachesize>[^\s]+)\s+(?P<dcachesize_f>[^\s]+)\s+(?P<numfile>[^\s]+)\s+(?P<numfile_f>[^\s]+)\s+(?P<numiptent>[^\s]+)\s+(?P<numiptent_f>[^\s]+)\s+(?P<diskspace>[^\s]+)\s+(?P<diskspace_s>[^\s]+)\s+(?P<diskspace_h>[^\s]+)\s+(?P<diskinodes>[^\s]+)\s+(?P<diskinodes_s>[^\s]+)\s+(?P<diskinodes_h>[^\s]+)\s+(?P<laverage>[^\s]+)/', $out, $matches);
		// build a list of servers, and then send an update command to make usre that the server has information on all servers
		foreach ($matches['ctid'] as $key => $id)
		{
			$server = array(
				'veid' => $id,
				'numproc' => $matches['numproc'][$key],
				'status' => $matches['status'][$key],
				'ip' => $matches['ip'][$key],
				'hostname' => $matches['hostname'][$key],
				'vswap' => $matches['vswap'][$key],
				'layout' => $matches['layout'][$key],
				'kmemsize' => $matches['kmemsize'][$key],
				'kmemsize_f' => $matches['kmemsize_f'][$key],
				'lockedpages' => $matches['lockedpages'][$key],
				'lockedpages_f' => $matches['lockedpages_f'][$key],
				'privvmpages' => $matches['privvmpages'][$key],
				'privvmpages_f' => $matches['privvmpages_f'][$key],
				'shmpages' => $matches['shmpages'][$key],
				'shmpages_f' => $matches['shmpages_f'][$key],
				'numproc_f' => $matches['numproc_f'][$key],
				'physpages' => $matches['physpages'][$key],
				'physpages_f' => $matches['physpages_f'][$key],
				'vmguarpages' => $matches['vmguarpages'][$key],
				'vmguarpages_f' => $matches['vmguarpages_f'][$key],
				'oomguarpages' => $matches['oomguarpages'][$key],
				'oomguarpages_f' => $matches['oomguarpages_f'][$key],
				'numtcpsock' => $matches['numtcpsock'][$key],
				'numtcpsock_f' => $matches['numtcpsock_f'][$key],
				'numflock' => $matches['numflock'][$key],
				'numflock_f' => $matches['numflock_f'][$key],
				'numpty' => $matches['numpty'][$key],
				'numpty_f' => $matches['numpty_f'][$key],
				'numsiginfo' => $matches['numsiginfo'][$key],
				'numsiginfo_f' => $matches['numsiginfo_f'][$key],
				'tcpsndbuf' => $matches['tcpsndbuf'][$key],
				'tcpsndbuf_f' => $matches['tcpsndbuf_f'][$key],
				'tcprcvbuf' => $matches['tcprcvbuf'][$key],
				'tcprcvbuf_f' => $matches['tcprcvbuf_f'][$key],
				'othersockbuf' => $matches['othersockbuf'][$key],
				'othersockbuf_f' => $matches['othersockbuf_f'][$key],
				'dgramrcvbuf' => $matches['dgramrcvbuf'][$key],
				'dgramrcvbuf_f' => $matches['dgramrcvbuf_f'][$key],
				'numothersock' => $matches['numothersock'][$key],
				'numothersock_f' => $matches['numothersock_f'][$key],
				'dcachesize' => $matches['dcachesize'][$key],
				'dcachesize_f' => $matches['dcachesize_f'][$key],
				'numfile' => $matches['numfile'][$key],
				'numfile_f' => $matches['numfile_f'][$key],
				'numiptent' => $matches['numiptent'][$key],
				'numiptent_f' => $matches['numiptent_f'][$key],
				'diskspace' => $matches['diskspace'][$key],
				'diskspace_s' => $matches['diskspace_s'][$key],
				'diskspace_h' => $matches['diskspace_h'][$key],
				'diskinodes' => $matches['diskinodes'][$key],
				'diskinodes_s' => $matches['diskinodes_s'][$key],
				'diskinodes_h' => $matches['diskinodes_h'][$key],
				'laverage' => $matches['laverage'][$key],
			);
			$servers[$id] = $server;
		}
		foreach ($servers as $id => $server)
		{
			if ($id == 0)
				continue;
			$cmd = "export PATH=\"\$PATH:/bin:/usr/bin:/sbin:/usr/sbin\";if [ -e /vz/private/{$id}/root.hdd/DiskDescriptor.xml ];then ploop info /vz/private/{$id}/root.hdd/DiskDescriptor.xml 2>/dev/null | grep blocks | awk '{ print \$3 \" \" \$2 }'; else vzquota stat $id 2>/dev/null | grep blocks | awk '{ print \$2 \" \" \$3 }'; fi;";
			//echo "Running $cmd\n";
			$out = trim(`$cmd`);
			if ($out != '')
			{
				$disk = explode(' ', $out);
				$servers[$id]['diskused'] = $disk[0];
				$servers[$id]['diskmax'] = $disk[1];
			}
		}
		if ($cpu_usage = @unserialize(`bash /root/cpaneldirect/cpu_usage.sh -serialize`))
		{
			foreach ($cpu_usage as $id => $cpu_data)
			{
				//$servers[$id]['cpu_usage'] = serialize($cpu_data);
				$servers[$id]['cpu_usage'] = $cpu_data;
			}
		}
		//print_r($servers);
		$tips = trim(`/root/cpaneldirect/vps_get_ip_assignments.sh`);
		if ($tips != '')
		{
			$tips = explode("\n", $tips);
			foreach ($tips as $line)
			{
				$parts = explode(' ', $line);
				$ips[$parts[0]] = array();
				foreach ($parts as $idx => $ip)
				{
					if ($idx == 0)
						continue;
					$ips[$parts[0]][] = $ip;
				}
				//$servers[$id]['ips'] = $ips[$parts[0];
			}
		}
	}
	//if (preg_match_all("/^[ ]*(?P<dev>[\w]+):(?P<inbytes>[\d]+)[ ]+(?P<inpackets>[\d]+)[ ]+(?P<inerrs>[\d]+)[ ]+(?P<indrop>[\d]+)[ ]+(?P<infifo>[\d]+)[ ]+(?P<inframe>[\d]+)[ ]+(?P<incompressed>[\d]+)[ ]+(?P<inmulticast>[\d]+)[ ]+(?P<outbytes>[\d]+)[ ]+(?P<outpackets>[\d]+)[ ]+(?P<outerrs>[\d]+)[ ]+(?P<outdrop>[\d]+)[ ]+(?P<outfifo>[\d]+)[ ]+(?P<outcolls>[\d]+)[ ]+(?P<outcarrier>[\d]+)[ ]+(?P<outcompressed>[\d]+)[ ]*$/im", file_get_contents('/proc/net/dev'), $matches))
	if (preg_match_all("/^[ ]*([\w]+):\s*([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]*$/im", file_get_contents('/proc/net/dev'), $matches))
	{
		$bw = array(time(), 0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0);
		foreach ($matches[1] as $idx => $dev)
		{
			if (substr($dev, 0, 3) == 'eth')
			{
				for ($x = 1; $x < 16; $x++)
				{
					$bw[$x] += $matches[$x+1][$idx];
				}
			}
		}
		$bw_usage = array(
			'time' => $bw[0],
			'bytes_in' => $bw[1],
			'packets_in' => $bw[2],
			'bytes_sec_in' => 0,
			'packets_sec_in' => 0,
			'bytes_out' => $bw[9],
			'packets_out' => $bw[10],
			'bytes_sec_out' => 0,
			'packets_sec_out' => 0,
			'bytes_total' => $bw[1] + $bw[9],
			'packets_total' => $bw[2] + $bw[10],
			'bytes_sec_total' => 0,
			'packets_sec_total' => 0,
		);
		if (file_exists('/root/.bw_usage.last'))
		{
			$bw_last = unserialize(file_get_contents('/root/.bw_usage.last'));
			$bw_usage_last = array(
				'time' => $bw_last[0],
				'bytes_in' => $bw_last[1],
				'packets_in' => $bw_last[2],
				'bytes_sec_in' => 0,
				'packets_sec_in' => 0,
				'bytes_out' => $bw_last[9],
				'packets_out' => $bw_last[10],
				'bytes_sec_out' => 0,
				'packets_sec_out' => 0,
				'bytes_total' => $bw_last[1] + $bw_last[9],
				'packets_total' => $bw_last[2] + $bw_last[10],
				'bytes_sec_total' => 0,
				'packets_sec_total' => 0,
			);
			$time_diff = $bw[0] - $bw_last[0];
			foreach(array('bytes', 'packets') as $stat)
			{
				foreach (array('in','out','total') as $dir)
				{
					$bw_usage[$stat . '_sec_' . $dir] = ($bw_usage[$stat . '_' . $dir] - $bw_usage_last[$stat . '_' . $dir]) / $time_diff;
				}
			}
		}
		file_put_contents('/root/.bw_usage.last', serialize($bw));
		$servers[0]['bw_usage'] = $bw_usage;
	}
	// ensure ethtool is installed
	`if ! which ethtool 2>/dev/null; then if [ -e /etc/redhat-release ]; then yum install -y ethtool; else apt-get install -y ethtool; fi; fi;`;
	//$speed = trim(`ethtool $(brctl show $(ip route |grep ^default | sed s#"^.*dev \([^ ]*\) .*$"#"\1"#g)  |grep -v "bridge id" | awk '{ print $4 }') |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g`);
	if (in_array(trim(`hostname`), array("kvm1.trouble-free.net", "kvm2.interserver.net", "kvm50.interserver.net")))
		$eth = 'eth1';
	elseif (file_exists('/etc/debian_version'))
	{
		if (file_exists('/sys/class/net/p2p1'))
			$eth = 'p2p1';
		elseif (file_exists('/sys/class/net/em1'))
			$eth = 'em1';
		else
			$eth = 'eth0';
	}
	else
		$eth = 'eth0';
	$cmd = 'ethtool '.$eth.' 2>/dev/null |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g';
	$speed = trim(`{$cmd}`);
	if ($speed == '') {
		$cmd = 'ethtool $(brctl show $(ip route |grep ^default | sed s#"^.*dev \([^ ]*\) .*$"#"\1"#g) 2>/dev/null |grep -v "bridge id" | awk \'{ print $4 }\') |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g';
		$speed = trim(`{$cmd}`);
	}
        //echo "Running {$cmd}\n";
        //echo "Got Speed {$speed}\n";
	$cpuinfo = explode("\n", file_get_contents('/proc/cpuinfo'));
	$found = false;
	$lines = sizeof($cpuinfo);
	$line = 0;
	while ($found != true && $line < $lines)
	{
		$cpuline = $cpuinfo[$line];
		if (substr($cpuline, 0, 5) == 'flags')
		{
			$flags = explode(' ', trim(substr($cpuline, strpos($cpuline, ':') + 1)));
			$found = true;
		}
		else
		{
			$line++;
		}
	}
	sort($flags);
	$flagsnew = implode(' ', $flags);
	$flags = $flagsnew;
	unset($flagsnew);

	if (file_exists('/etc/redhat-release'))
	{
		preg_match('/^(?P<distro>[\w]+)( Linux)? release (?P<version>[\S]+)( .*)*$/i', file_get_contents('/etc/redhat-release'), $matches);
	}
	else
	{
		preg_match('/DISTRIB_ID=(?P<distro>[^<]+)<br>DISTRIB_RELEASE=(?P<version>[^<]+)<br>/i', str_replace("\n", '<br>', file_get_contents('/etc/lsb-release')), $matches);
	}
	$servers[0]['os_info'] = array(
		'distro' => $matches['distro'],
		'version' => $matches['version'],
		'speed' => $speed,
		'cpu_flags' => $flags,
	);
	$cmd = 'curl --connect-timeout 60 --max-time 600 -k -F action=serverlist -F servers="' . base64_encode(gzcompress(serialize($servers), 9)) . '"  '
	. (isset($ips) ? ' -F ips="' . base64_encode(gzcompress(serialize($ips), 9)) . '" ' : '')
//	. ($cpu_data != '' ? ' -F cpu_usage="' . base64_encode(gzcompress($cpu_data, 9)) . '" ' : '')
	. $curl_cmd . ' "' . $url . '" 2>/dev/null;';
//		$cmd = 'curl --connect-timeout 60 --max-time 600 -k -F action=serverlist -F servers="' . base64_encode(gzcompress(serialize($servers), 9)) . '" $curlcmd "' . $url . '" 2>/dev/null;';
	//echo "CMD: $cmd\n";
	$cmd .= '/bin/rm -f shot_*jpg shot_*jpg.gz 2>/dev/null;';
	//echo "OK now doing something else on " . __LINE__ . "\n";
	echo trim(`$cmd`);
}

get_vps_list();
