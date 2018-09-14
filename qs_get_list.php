#!/usr/bin/env php
<?php

require_once(dirname(__FILE__).'/xml2array.php');

/**
 * get_qs_list()
 *
 * @return
 */
function get_qs_list()
{
	$dir = __DIR__;
	$url = 'https://myquickserver2.interserver.net/qs_queue.php';
	if (!file_exists('/usr/sbin/vzctl')) {
		$out = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";virsh list --all | grep -v -e "State\$" -e "------\$" -e "^\$" | awk "{ print \\\$2 \" \" \\\$3 }"`);
		$lines = explode("\n", $out);
		$servers = array();
		$cmd = '';
		foreach ($lines as $serverline) {
			if (trim($serverline) != '') {
				$parts = explode(' ', $serverline);
				$name = $parts[0];
				$veid = $name;
				//$veid = str_replace(array('windows', 'linux', 'qs'), array('', ''), $veid);
				$status = $parts[1];
				$out = `export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";virsh dumpxml $name`;
				$xml = xml2array($out);
				$server = array(
					'veid' => $veid,
					'status' => $status,
					'name' => $name,
					'hostname' => $name,
					'kmemsize' => $xml['domain']['memory'],
				);
				if (isset($xml['domain']['devices']['interface'])) {
					$server['mac'] = $xml['domain']['devices']['interface']['mac_attr']['address'];
				}
				if (isset($xml['domain']['devices']['graphics_attr'])) {
					$server['vnc'] = $xml['domain']['devices']['graphics_attr']['port'];
				}
				if ($status == 'running') {
					/*
										$disk = trim(`{$dir}/vps_kvm_disk_usage.sh $name`);
										if ($disk != '')
										{
											$dparts = explode(':', $disk);
											$server['diskused'] = $dparts[2];
											$server['diskmax'] = $dparts[1];
										}
					*/
					if (isset($xml['domain']['devices']['graphics_attr'])) {
						if ($xml['domain']['devices']['graphics_attr']['port'] >= 5900) {
							//echo "Port:" . $xml['domain']['devices']['graphics_attr']['port'].PHP_EOL;
							$vncdisplay = (integer)abs($xml['domain']['devices']['graphics_attr']['port'] - 5900);
							$cmd .= "{$dir}/vps_kvm_screenshot.sh $vncdisplay '$url?action=screenshot&name=$name' &\n";
						}
					}
				}
				$servers[$veid] = $server;
			}
		}
		//echo "CMD:$cmd\n";
		echo `$cmd`;
	} else {
		$out = `export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzlist -a -o veid,numproc,status,ip,hostname,kmemsize,kmemsize.f,lockedpages,lockedpages.f,privvmpages,privvmpages.f,shmpages,shmpages.f,numproc.f,physpages,physpages.f,vmguarpages,vmguarpages.f,oomguarpages,oomguarpages.f,numtcpsock,numtcpsock.f,numflock,numflock.f,numpty,numpty.f,numsiginfo,numsiginfo.f,tcpsndbuf,tcpsndbuf.f,tcprcvbuf,tcprcvbuf.f,othersockbuf,othersockbuf.f,dgramrcvbuf,dgramrcvbuf.f,numothersock,numothersock.f,dcachesize,dcachesize.f,numfile,numfile.f,numiptent,numiptent.f,diskspace,diskspace.s,diskspace.h,diskinodes,diskinodes.s,diskinodes.h,laverage,cpulimit,cpuunits -H`;
		preg_match_all('/\s+(?P<veid>[^\s]+)\s+(?P<numproc>[^\s]+)\s+(?P<status>[^\s]+)\s+(?P<ip>[^\s]+)\s+(?P<hostname>[^\s]+)\s+(?P<kmemsize>[^\s]+)\s+(?P<kmemsize_f>[^\s]+)\s+(?P<lockedpages>[^\s]+)\s+(?P<lockedpages_f>[^\s]+)\s+(?P<privvmpages>[^\s]+)\s+(?P<privvmpages_f>[^\s]+)\s+(?P<shmpages>[^\s]+)\s+(?P<shmpages_f>[^\s]+)\s+(?P<numproc_f>[^\s]+)\s+(?P<physpages>[^\s]+)\s+(?P<physpages_f>[^\s]+)\s+(?P<vmguarpages>[^\s]+)\s+(?P<vmguarpages_f>[^\s]+)\s+(?P<oomguarpages>[^\s]+)\s+(?P<oomguarpages_f>[^\s]+)\s+(?P<numtcpsock>[^\s]+)\s+(?P<numtcpsock_f>[^\s]+)\s+(?P<numflock>[^\s]+)\s+(?P<numflock_f>[^\s]+)\s+(?P<numpty>[^\s]+)\s+(?P<numpty_f>[^\s]+)\s+(?P<numsiginfo>[^\s]+)\s+(?P<numsiginfo_f>[^\s]+)\s+(?P<tcpsndbuf>[^\s]+)\s+(?P<tcpsndbuf_f>[^\s]+)\s+(?P<tcprcvbuf>[^\s]+)\s+(?P<tcprcvbuf_f>[^\s]+)\s+(?P<othersockbuf>[^\s]+)\s+(?P<othersockbuf_f>[^\s]+)\s+(?P<dgramrcvbuf>[^\s]+)\s+(?P<dgramrcvbuf_f>[^\s]+)\s+(?P<numothersock>[^\s]+)\s+(?P<numothersock_f>[^\s]+)\s+(?P<dcachesize>[^\s]+)\s+(?P<dcachesize_f>[^\s]+)\s+(?P<numfile>[^\s]+)\s+(?P<numfile_f>[^\s]+)\s+(?P<numiptent>[^\s]+)\s+(?P<numiptent_f>[^\s]+)\s+(?P<diskspace>[^\s]+)\s+(?P<diskspace_s>[^\s]+)\s+(?P<diskspace_h>[^\s]+)\s+(?P<diskinodes>[^\s]+)\s+(?P<diskinodes_s>[^\s]+)\s+(?P<diskinodes_h>[^\s]+)\s+(?P<laverage>[^\s]+)\s+(?P<cpulimit>[^\s]+)\s+(?P<cpuunits>[^\s]+)/', $out, $matches);
		//print_r($matches);
		$servers = array();
		// build a list of servers, and then send an update command to make usre that the server has information on all servers
		foreach ($matches['veid'] as $key => $id) {
			$server = array(
				'veid' => $id,
				'numproc' => $matches['numproc'][$key],
				'status' => $matches['status'][$key],
				'ip' => $matches['ip'][$key],
				'hostname' => $matches['hostname'][$key],
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
				'cpulimit' => $matches['cpulimit'][$key],
				'cpuunits' => $matches['cpuunits'][$key]
			);
			$servers[$id] = $server;
		}
		foreach ($servers as $id => $server) {
			$out = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";vzquota stat $id 2>/dev/null | grep blocks | awk '{ print $2 " " $3 }'`);
			if ($out != '') {
				$disk = explode(' ', $out);
				$servers[$id]['diskused'] = $disk[0];
				$servers[$id]['diskmax'] = $disk[1];
			}
		}
	}
	//print_r($servers);
	$cmd = 'curl --connect-timeout 60 --max-time 600 -k -d action=server_list -d servers="'.urlencode(base64_encode(gzcompress(serialize($servers), 9))).'" "'.$url.'" 2>/dev/null;';
	//echo "CMD: $cmd\n";
	echo trim(`$cmd`);
}

get_qs_list();
