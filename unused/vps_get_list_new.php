#!/usr/bin/env php
<?php

require_once(dirname(__FILE__).'/xml2array.php');

/**
 * get_vps_list()
 *
 * @return
 */
function get_vps_list()
{
	$dir = __DIR__;
	$url = 'https://myvps.interserver.net/vps_queue.php';
	$curl_cmd = '';
	$servers = array();
	if (file_exists('/usr/bin/lxc')) {
		$lines = explode("\n", trim(`lxc list -c ns4,volatile.eth0.hwaddr:MAC --format csv`));
		foreach ($lines as $line) {
			$parts = explode(',', $line);
			$server = array(
				'type' => 'lxc',
				'veid' => $parts[0],
				'status' => isset($parts[1]) ? strtolower($parts[1]) : 'stopped',
			);
			if (isset($parts[2])) {
				$ipparts = explode(" ", $parts[2]);
				$server['ip'] = $ipparts[0];
				$ips[$parts[0]] = $ipparts[0];
			}
			if (trim($parts[3]) != '') {
				$server['mac'] = trim($parts[3]);
			}
			$servers[$parts[0]] = $server;
		}
	}
	if (file_exists('/usr/bin/virsh')) {
		$cmd = 'export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/bin:/usr/bin:/sbin:/usr/sbin";virsh list --all | grep -v -e "State$" -e "------$" -e "^$" | awk "{ print \$2 \" \" \$3 }"';
		//echo "Running $cmd\n";
		$out = trim(`$cmd`);
		$lines = explode("\n", $out);
		$cmd = '';
		foreach ($lines as $serverline) {
			if (trim($serverline) != '') {
				$parts = explode(' ', $serverline);
				$name = $parts[0];
				$veid = $name;
				//$veid = str_replace(array('windows', 'linux'), array('', ''), $veid);
				$status = $parts[1];
				$out = `export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";virsh dumpxml $name`;
				$xml = xml2array($out);
				$server = array(
					'type' => 'kvm',
					'veid' => $veid,
					'status' => $status,
					'hostname' => $name,
					'kmemsize' => $xml['domain']['memory'],
				);
				if (isset($xml['domain']['devices']['interface'])) {
					if (isset($xml['domain']['devices']['interface']['mac_attr'])) {
						$server['mac'] = $xml['domain']['devices']['interface']['mac_attr']['address'];
					} elseif (isset($xml['domain']['devices']['interface'][0]['mac_attr'])) {
						$server['mac'] = $xml['domain']['devices']['interface'][0]['mac_attr']['address'];
					}
				}
				if (isset($xml['domain']['devices']['graphics_attr'])) {
					$server['vnc'] = $xml['domain']['devices']['graphics_attr']['port'];
				}
				if ($status == 'running') {
					if (isset($xml['domain']['devices']['graphics_attr'])) {
						$port = (integer)$xml['domain']['devices']['graphics_attr']['port'];
						if ($port >= 5900) {
							// vncsnapshot Encodings: raw copyrect tight hextile zlib corre rre zrle
							$cmd .= "if [ -e /usr/bin/timeout ]; then
	timeout 30s ./vncsnapshot -dieblank -compresslevel 0 -quality 70 -vncQuality 7 -jpeg -fps 5 -count 1 -quiet -encodings raw :\$(($port - 5900)) shot_{$port}.jpg >/dev/null 2>&1;
else
	./vncsnapshot -dieblank -compresslevel 0 -quality 70 -vncQuality 7 -jpeg -fps 5 -count 1 -quiet -encodings raw :\$(($port - 5900)) shot_{$port}.jpg >/dev/null 2>&1;
fi;\n";
						}
					}
				}
				$servers[$veid] = $server;
			}
		}
		if ($cpu_usage = @unserialize(`bash {$dir}/cpu_usage.sh -serialize`)) {
			foreach ($cpu_usage as $id => $cpu_data) {
				$servers[$id]['cpu_usage'] = $cpu_data;
			}
		}
		$curl_cmd = '$(for i in shot_*jpg; do if [ "$i" != "shot_*jpg" ]; then p=$(echo $i | cut -c5-9); gzip -9 -f $i; echo -n " -F shot$p=@${i}.gz"; fi; done;)';
		echo `$cmd`;
	}
	if (file_exists('/usr/sbin/vzctl') || file_exists('/usr/bin/prlctl')) {
		// build a list of servers, and then send an update command to make usre that the server has information on all servers
		if (file_exists('/usr/bin/prlctl')) {
			$type = 'virtuozzo';
			//$out = trim(`/usr/bin/prlctl list -a -o uuid,name,status,ip,hostname,type -H;`);
			//preg_match_all('/^\s*(?P<uuid>[^\s\}]+)\s+(?P<ctid>[^\s]+)\s+(?P<status>[^\s]+)\s+(?P<ip>[^\s]+)\s+(?P<hostname>[^\s]+)\s+(?P<type>[^\s]+)$/m', $out, $matches);
			$out = trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzlist -a -o uuid,name,numproc,status,ip,hostname,swappages,layout,kmemsize,kmemsize.f,lockedpages,lockedpages.f,privvmpages,privvmpages.f,shmpages,shmpages.f,numproc.f,physpages,physpages.f,vmguarpages,vmguarpages.f,oomguarpages,oomguarpages.f,numtcpsock,numtcpsock.f,numflock,numflock.f,numpty,numpty.f,numsiginfo,numsiginfo.f,tcpsndbuf,tcpsndbuf.f,tcprcvbuf,tcprcvbuf.f,othersockbuf,othersockbuf.f,dgramrcvbuf,dgramrcvbuf.f,numothersock,numothersock.f,dcachesize,dcachesize.f,numfile,numfile.f,numiptent,numiptent.f,diskspace,diskspace.s,diskspace.h,diskinodes,diskinodes.s,diskinodes.h,laverage -H;`);
			preg_match_all('/^\s*(?P<uuid>[^\s]+)\s+(?P<ctid>[^\s]+)\s+(?P<numproc>[^\s]+)\s+(?P<status>[^\s]+)\s+(?P<ip>[^\s]+)\s+(?P<hostname>[^\s]+)\s+(?P<vswap>[^\s]+)\s+(?P<layout>[^\s]+)\s+(?P<kmemsize>[^\s]+)\s+(?P<kmemsize_f>[^\s]+)\s+(?P<lockedpages>[^\s]+)\s+(?P<lockedpages_f>[^\s]+)\s+(?P<privvmpages>[^\s]+)\s+(?P<privvmpages_f>[^\s]+)\s+(?P<shmpages>[^\s]+)\s+(?P<shmpages_f>[^\s]+)\s+(?P<numproc_f>[^\s]+)\s+(?P<physpages>[^\s]+)\s+(?P<physpages_f>[^\s]+)\s+(?P<vmguarpages>[^\s]+)\s+(?P<vmguarpages_f>[^\s]+)\s+(?P<oomguarpages>[^\s]+)\s+(?P<oomguarpages_f>[^\s]+)\s+(?P<numtcpsock>[^\s]+)\s+(?P<numtcpsock_f>[^\s]+)\s+(?P<numflock>[^\s]+)\s+(?P<numflock_f>[^\s]+)\s+(?P<numpty>[^\s]+)\s+(?P<numpty_f>[^\s]+)\s+(?P<numsiginfo>[^\s]+)\s+(?P<numsiginfo_f>[^\s]+)\s+(?P<tcpsndbuf>[^\s]+)\s+(?P<tcpsndbuf_f>[^\s]+)\s+(?P<tcprcvbuf>[^\s]+)\s+(?P<tcprcvbuf_f>[^\s]+)\s+(?P<othersockbuf>[^\s]+)\s+(?P<othersockbuf_f>[^\s]+)\s+(?P<dgramrcvbuf>[^\s]+)\s+(?P<dgramrcvbuf_f>[^\s]+)\s+(?P<numothersock>[^\s]+)\s+(?P<numothersock_f>[^\s]+)\s+(?P<dcachesize>[^\s]+)\s+(?P<dcachesize_f>[^\s]+)\s+(?P<numfile>[^\s]+)\s+(?P<numfile_f>[^\s]+)\s+(?P<numiptent>[^\s]+)\s+(?P<numiptent_f>[^\s]+)\s+(?P<diskspace>[^\s]+)\s+(?P<diskspace_s>[^\s]+)\s+(?P<diskspace_h>[^\s]+)\s+(?P<diskinodes>[^\s]+)\s+(?P<diskinodes_s>[^\s]+)\s+(?P<diskinodes_h>[^\s]+)\s+(?P<laverage>[^\s]+)/m', $out, $matches);
			$uuids = array();
			$veids = array();
			foreach ($matches['uuid'] as $key => $id) {
				$id = str_replace(array('{','}'), array('',''), $id);
				$veids[$matches['ctid'][$key]] = $id;
				$uuids[$id] = $matches['ctid'][$key];
				$server = array(
					'type' => $type,
					'uuid' => $id,
					'veid' => $id,
					'numproc' => $matches['numproc'][$key],
					'status' => $matches['status'][$key],
					'ip' => $matches['ip'][$key],
					'hostname' => $matches['hostname'][$key],
				);
				$servers[$id] = $server;
			}
		} else {
			$type = 'openvz';
			$out = trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";if [ "\$(vzlist -L |grep vswap)" = "" ]; then vzlist -a -o ctid,numproc,status,ip,hostname,swappages,layout,kmemsize,kmemsize.f,lockedpages,lockedpages.f,privvmpages,privvmpages.f,shmpages,shmpages.f,numproc.f,physpages,physpages.f,vmguarpages,vmguarpages.f,oomguarpages,oomguarpages.f,numtcpsock,numtcpsock.f,numflock,numflock.f,numpty,numpty.f,numsiginfo,numsiginfo.f,tcpsndbuf,tcpsndbuf.f,tcprcvbuf,tcprcvbuf.f,othersockbuf,othersockbuf.f,dgramrcvbuf,dgramrcvbuf.f,numothersock,numothersock.f,dcachesize,dcachesize.f,numfile,numfile.f,numiptent,numiptent.f,diskspace,diskspace.s,diskspace.h,diskinodes,diskinodes.s,diskinodes.h,laverage -H; else vzlist -a -o ctid,numproc,status,ip,hostname,vswap,layout,kmemsize,kmemsize.f,lockedpages,lockedpages.f,privvmpages,privvmpages.f,shmpages,shmpages.f,numproc.f,physpages,physpages.f,vmguarpages,vmguarpages.f,oomguarpages,oomguarpages.f,numtcpsock,numtcpsock.f,numflock,numflock.f,numpty,numpty.f,numsiginfo,numsiginfo.f,tcpsndbuf,tcpsndbuf.f,tcprcvbuf,tcprcvbuf.f,othersockbuf,othersockbuf.f,dgramrcvbuf,dgramrcvbuf.f,numothersock,numothersock.f,dcachesize,dcachesize.f,numfile,numfile.f,numiptent,numiptent.f,diskspace,diskspace.s,diskspace.h,diskinodes,diskinodes.s,diskinodes.h,laverage -H; fi;`);
			preg_match_all('/^\s+(?P<ctid>[^\s]+)\s+(?P<numproc>[^\s]+)\s+(?P<status>[^\s]+)\s+(?P<ip>[^\s]+)\s+(?P<hostname>[^\s]+)\s+(?P<vswap>[^\s]+)\s+(?P<layout>[^\s]+)\s+(?P<kmemsize>[^\s]+)\s+(?P<kmemsize_f>[^\s]+)\s+(?P<lockedpages>[^\s]+)\s+(?P<lockedpages_f>[^\s]+)\s+(?P<privvmpages>[^\s]+)\s+(?P<privvmpages_f>[^\s]+)\s+(?P<shmpages>[^\s]+)\s+(?P<shmpages_f>[^\s]+)\s+(?P<numproc_f>[^\s]+)\s+(?P<physpages>[^\s]+)\s+(?P<physpages_f>[^\s]+)\s+(?P<vmguarpages>[^\s]+)\s+(?P<vmguarpages_f>[^\s]+)\s+(?P<oomguarpages>[^\s]+)\s+(?P<oomguarpages_f>[^\s]+)\s+(?P<numtcpsock>[^\s]+)\s+(?P<numtcpsock_f>[^\s]+)\s+(?P<numflock>[^\s]+)\s+(?P<numflock_f>[^\s]+)\s+(?P<numpty>[^\s]+)\s+(?P<numpty_f>[^\s]+)\s+(?P<numsiginfo>[^\s]+)\s+(?P<numsiginfo_f>[^\s]+)\s+(?P<tcpsndbuf>[^\s]+)\s+(?P<tcpsndbuf_f>[^\s]+)\s+(?P<tcprcvbuf>[^\s]+)\s+(?P<tcprcvbuf_f>[^\s]+)\s+(?P<othersockbuf>[^\s]+)\s+(?P<othersockbuf_f>[^\s]+)\s+(?P<dgramrcvbuf>[^\s]+)\s+(?P<dgramrcvbuf_f>[^\s]+)\s+(?P<numothersock>[^\s]+)\s+(?P<numothersock_f>[^\s]+)\s+(?P<dcachesize>[^\s]+)\s+(?P<dcachesize_f>[^\s]+)\s+(?P<numfile>[^\s]+)\s+(?P<numfile_f>[^\s]+)\s+(?P<numiptent>[^\s]+)\s+(?P<numiptent_f>[^\s]+)\s+(?P<diskspace>[^\s]+)\s+(?P<diskspace_s>[^\s]+)\s+(?P<diskspace_h>[^\s]+)\s+(?P<diskinodes>[^\s]+)\s+(?P<diskinodes_s>[^\s]+)\s+(?P<diskinodes_h>[^\s]+)\s+(?P<laverage>[^\s]+)/m', $out, $matches);
			foreach ($matches['ctid'] as $key => $id) {
				$server = array(
					'type' => $type,
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
		}
		if (file_exists('/usr/bin/prlctl')) {
			$json_servers = json_decode(`prlctl list -a -j`, true);
			foreach ($json_servers as $json_server) {
				if (isset($servers[$json_server['uuid']])) {
					$servers[$json_server['uuid']]['ip'] = $json_server['ip_configured'];
				}
			}
			$json_servers = json_decode(`prlctl list -a -i -j`, true);
			foreach ($json_servers as $json_server) {
				if (isset($json_server['Remote display']) && isset($json_server['Remote display']['port']) && isset($servers[$json_server['ID']])) {
					$servers[$json_server['ID']]['vnc'] = $json_server['Remote display']['port'];
				}
			}
		}
		foreach ($servers as $id => $server) {
			if ($id == 0) {
				continue;
			}
			$cmd = "export PATH=\"/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin\";if [ -e /vz/private/{$id}/root.hdd/DiskDescriptor.xml ];then ploop info /vz/private/{$id}/root.hdd/DiskDescriptor.xml 2>/dev/null | grep blocks | awk '{ print \$3 \" \" \$2 }'; else vzquota stat $id 2>/dev/null | grep blocks | awk '{ print \$2 \" \" \$3 }'; fi;";
			//echo "Running $cmd\n";
			$out = trim(`$cmd`);
			if ($out != '') {
				if ($id > 0 && isset($uuids)) {
					$id = $uuids[$id];
				}
				if ($id == 0 || isset($servers[$id])) {
					$disk = explode(' ', $out);
					$servers[$id]['diskused'] = $disk[0];
					$servers[$id]['diskmax'] = $disk[1];
				}
			}
		}
		if ($cpu_usage = @unserialize(`bash {$dir}/cpu_usage.sh -serialize`)) {
			foreach ($cpu_usage as $id => $cpu_data) {
				if ($id > 0 && isset($uuids)) {
					$id = $uuids[$id];
				}
				if ($id == 0 || isset($servers[$id])) {
					$servers[$id]['cpu_usage'] = $cpu_data;
				}
			}
		}
		//print_r($servers);
		$tips = trim(`{$dir}/vps_get_ip_assignments.sh`);
		if ($tips != '') {
			$tips = explode("\n", $tips);
			foreach ($tips as $line) {
				$parts = explode(' ', $line);
				$ips[$parts[0]] = array();
				foreach ($parts as $idx => $ip) {
					if ($idx == 0) {
						continue;
					}
					$ips[$parts[0]][] = $ip;
				}
				//$servers[$id]['ips'] = $ips[$parts[0];
			}
		}
	}
	//if (preg_match_all("/^[ ]*(?P<dev>[\w]+):(?P<inbytes>[\d]+)[ ]+(?P<inpackets>[\d]+)[ ]+(?P<inerrs>[\d]+)[ ]+(?P<indrop>[\d]+)[ ]+(?P<infifo>[\d]+)[ ]+(?P<inframe>[\d]+)[ ]+(?P<incompressed>[\d]+)[ ]+(?P<inmulticast>[\d]+)[ ]+(?P<outbytes>[\d]+)[ ]+(?P<outpackets>[\d]+)[ ]+(?P<outerrs>[\d]+)[ ]+(?P<outdrop>[\d]+)[ ]+(?P<outfifo>[\d]+)[ ]+(?P<outcolls>[\d]+)[ ]+(?P<outcarrier>[\d]+)[ ]+(?P<outcompressed>[\d]+)[ ]*$/im", file_get_contents('/proc/net/dev'), $matches))
	if (preg_match_all("/^[ ]*([\w]+):\s*([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]+([\d]+)[ ]*$/im", file_get_contents('/proc/net/dev'), $matches)) {
		$bw = array(time(), 0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0);
		foreach ($matches[1] as $idx => $dev) {
			if (substr($dev, 0, 3) == 'eth') {
				for ($x = 1; $x < 16; $x++) {
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
		if (file_exists('/root/.bw_usage.last')) {
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
			foreach (array('bytes', 'packets') as $stat) {
				foreach (array('in','out','total') as $dir) {
					$bw_usage[$stat.'_sec_'.$dir] = ($bw_usage[$stat.'_'.$dir] - $bw_usage_last[$stat.'_'.$dir]) / $time_diff;
				}
			}
		}
		file_put_contents('/root/.bw_usage.last', serialize($bw));
		$servers[0]['bw_usage'] = $bw_usage;
	}
	// ensure ethtool is installed
	`if ! which ethtool 2>/dev/null; then if [ -e /etc/redhat-release ]; then yum install -y ethtool; else apt-get install -y ethtool; fi; fi;`;
	//$speed = trim(`ethtool $(brctl show $(ip route |grep ^default | sed s#"^.*dev \([^ ]*\) .*$"#"\1"#g)  |grep -v "bridge id" | awk '{ print $4 }') |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g`);
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
	$cmd = 'ethtool '.$eth.' 2>/dev/null |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g';
	$speed = trim(`{$cmd}`);
	if ($speed == '') {
		$cmd = 'ethtool $(brctl show $(ip route |grep ^default | sed s#"^.*dev \([^ ]*\) .*$"#"\1"#g) 2>/dev/null |grep -v "bridge id" | awk \'{ print $4 }\') |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g';
		$speed = trim(`{$cmd}`);
	}
	$cpuinfo = explode("\n", file_get_contents('/proc/cpuinfo'));
	$found = false;
	$lines = sizeof($cpuinfo);
	$line = 0;
	while ($found != true && $line < $lines) {
		$cpuline = $cpuinfo[$line];
		if (substr($cpuline, 0, 5) == 'flags') {
			$flags = explode(' ', trim(substr($cpuline, strpos($cpuline, ':') + 1)));
			$found = true;
		} else {
			$line++;
		}
	}
	sort($flags);
	$flagsnew = implode(' ', $flags);
	$flags = $flagsnew;
	unset($flagsnew);
	if (file_exists('/etc/redhat-release')) {
		preg_match('/^(?P<distro>[\w]+)( Linux)? release (?P<version>[\S]+)( .*)*$/i', file_get_contents('/etc/redhat-release'), $matches);
	} else {
		preg_match('/DISTRIB_ID=(?P<distro>[^<]+)<br>DISTRIB_RELEASE=(?P<version>[^<]+)<br>/i', str_replace("\n", '<br>', file_get_contents('/etc/lsb-release')), $matches);
	}
	$servers[0]['os_info'] = array(
		'distro' => $matches['distro'],
		'version' => $matches['version'],
		'speed' => $speed,
		'cpu_flags' => $flags,
	);
	$cmd = 'curl --connect-timeout 60 --max-time 600 -k -F action=server_list -F servers="'.base64_encode(gzcompress(serialize($servers), 9)).'"  '
	. (isset($ips) ? ' -F ips="'.base64_encode(gzcompress(serialize($ips), 9)).'" ' : '')
	. $curl_cmd.' "'.$url.'" 2>/dev/null;';
	$cmd .= '/bin/rm -f shot_*jpg shot_*jpg.gz 2>/dev/null;';
	echo trim(`$cmd`);
}

get_vps_list();
