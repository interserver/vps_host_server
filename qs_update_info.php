#!/usr/bin/env php
<?php

/**
 * update_qs_info()
 *
 * @return
 */
function update_qs_info() {
	$root_used = trim(`df -P /| awk '{ print $5 }' |grep % | sed s#"%"#""#g`);
	if ($root_used > 90)
	{
		$hostname = trim(`hostname;`);
		mail('hardware@interserver.net', $root_used.'% Disk Usage on '.$hostname, $root_used.'% Disk Usage on '.$hostname);
	}
	$url = 'https://myquickserver2.interserver.net/qs_queue.php';
	$server = array();
	$uname = posix_uname();
	$server['bits'] = $uname['machine'] == 'x86_64' ? 64 : 32;
	$server['kernel'] = $uname['release'];
	$server['raid_building'] = false;
	foreach (glob('/sys/block/md*/md/sync_action') as $file)
		if (trim(file_get_contents($file)) != 'idle')
			$server['raid_building'] = true;
		$file = explode(' ', trim(file_get_contents('/proc/loadavg')));
	$server['load'] = (float)$file[0];
	$file = explode("\n\n", trim(file_get_contents('/proc/cpuinfo')));
	$server['cores'] = count($file);
	preg_match('/^cpu MHz.*: (.*)$/m', $file[0], $matches);
	$server['cpu_mhz'] = (float)$matches[1];
	preg_match('/^model name.*: (.*)$/m', $file[0], $matches);
	$server['cpu_model'] = $matches[1];
	preg_match('/MemTotal\s*\:\s*(\d+)/i', file_get_contents('/proc/meminfo'), $matches);
	$server['ram'] = (int)$matches[1];
	preg_match_all('/^(\/\S+)\s+(\S+)\s.*$/m', file_get_contents('/proc/mounts'), $matches);
	foreach ($matches[1] as $idx => $value) {
		$dev = $value;
		$dir = $matches[2][$idx];
		$total = floor(disk_total_space($dir) / 1073741824);
		$free = floor(disk_free_space($dir) / 1073741824);
		$used = $total - $free;
		$mounts[] = $dev.':'.$total.':'.$used.':'.$free.':'.$dir;
	}
	preg_match_all('/^\s*([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):(.*)$/m', trim(`pvdisplay -c`), $matches);
	foreach ($matches[1] as $idx => $value) {
		$dev = $value;
		$dir = $matches[2][$idx];
		$total = floor($matches[9][$idx] * $matches[8][$idx] / 1048576);
		$free = floor($matches[10][$idx] * $matches[8][$idx] / 1048576);
		$used = floor($matches[11][$idx] * $matches[8][$idx] / 1048576);
		$mounts[] = $dev.':'.$total.':'.$used.':'.$free.':'.$dir;
	}
	$server['mounts'] = implode(',', $mounts);
	$server['drive_type'] = trim(`if [ "$(smartctl -i /dev/sda |grep "SSD")" != "" ]; then echo SSD; else echo SATA; fi`);
	$server['raid_status'] = trim(`/root/cpaneldirect/check_raid.sh --check=WARNING 2>/dev/null`);
	if (file_exists('/usr/bin/iostat'))
	{
		$server['iowait'] = trim(`iostat -c  |grep -v "^$" | tail -n 1 | awk '{ print $4 }';`);
	}
	$cmd = 'if [ "$(which vzctl 2>/dev/null)" = "" ]; then 
	  iodev="/$(pvdisplay -c |grep -v -e centos -e backup -e vz-snap |grep :|cut -d/ -f2- |cut -d: -f1|head -n 1)";
	else 
	  iodev=/vz; 
	fi; 
	ioping -c 3 -s 100m -D -i 0 ${iodev} -B | cut -d" " -f2;';
	$server['ioping'] = trim(`$cmd`);
	if (file_exists('/usr/sbin/vzctl')) {
		$out = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";df -B G /vz | grep -v ^Filesystem | awk '{ print \$2 " " \$4 }' |sed s#"G"#""#g;`);
	} elseif (file_exists('/usr/bin/lxc')) {
		$parts = explode("\n", trim(`lxc storage info lxd --bytes|grep -e "space used:" -e "total space:"|cut -d'"' -f2`));
		$used = ceil($parts[0]/1073741824);
		$total = ceil($parts[1]/1073741824);
		$free = $total - $used;
		$out = $total.' '.$free;
	} elseif (file_exists('/usr/bin/virsh')) {
		if (file_exists('/etc/redhat-release') && strpos(file_get_contents('/etc/redhat-release'),'CentOS release 6') !== false)
			$out = '';
		else
			$out = trim(`virsh pool-info vz --bytes|awk '{ print \$2 }'`);
		if ($out != '') {
			$parts = explode("\n", $out);
			$totalb = $parts[5];
			$usedb = $parts[6];
			$freeb = $parts[7];
			$totalg = ceil($totalb / 1073741824);
			$freeg = ceil($freeb / 1073741824);
			$usedg = ceil($usedb / 1073741824);
			$out = $totalg.' '.$freeg;
		} elseif (trim(`lvdisplay  |grep 'Allocated pool';`) == '') {
			$parts = explode(':', trim(`export PATH="\$PATH:/sbin:/usr/sbin"; pvdisplay -c|grep : |grep -v -e centos -e backup`));
			$pesize = $parts[7];
			$totalpe = $parts[8];
			$freepe = $parts[9];
			$totalg = ceil($pesize * $totalpe / 1048576);
			$freeg = ceil($pesize * $freepe / 1048576);
			$out = $totalg.' '.$freeg;
		} else {
			$TOTAL_GB = '$(lvdisplay --units g /dev/vz/thin |grep "LV Size" | sed s#"^.*LV Size"#""#g | sed s#"GiB"#""#g | sed s#" "#""#g | cut  -d\. -f1)';
			$USED_PCT = '$(lvdisplay --units g /dev/vz/thin |grep "Allocated .*data" | sed s#"Allocated.*data"#""#g |sort -nr| head -n1 |sed s#"%"#""#g)';
			$GB_PER_PCT = $TOTAL_GB / 100;
			$USED_GB = floor($USED_PCT * $GB_PER_PCT);
			$MAX_PCT =  60;
			$FREE_PCT = floor($MAX_PCT - $USED_PCT);
			$FREE_GB = floor($GB_PER_PCT * $FREE_PCT);
			$out = $TOTAL_GB.' '.$FREE_GB;
		}
	}
	if (isset($out)) {
		$parts = explode(' ', $out);
		if (sizeof($parts) == 2)
		{
			$server['hdsize'] = $parts[0];
			$server['hdfree'] = $parts[1];
		}
	}
	$cmd = 'curl --connect-timeout 60 --max-time 600 -k -d action=qs_info -d servers="'.urlencode(base64_encode(serialize($server))).'" "'.$url.'" 2>/dev/null;';
	// echo "CMD: $cmd\n";
	echo trim(`$cmd`);
}

update_qs_info();
