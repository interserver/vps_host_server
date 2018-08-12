#!/usr/bin/env php
<?php

/**
 * update_vps_info()
 *
 * @return
 */
function update_vps_info() {
	$root_used = trim(`df -P /| awk '{ print $5 }' |grep % | sed s#"%"#""#g`);
	if ($root_used > 90)
	{
		$hostname = trim(`hostname;`);
		mail('hardware@interserver.net', $root_used.'% Disk Usage on '.$hostname, $root_used.'% Disk Usage on '.$hostname);
	}
	$url = 'https://myvps2.interserver.net/vps_queue.php';
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
	$file = file_get_contents('/proc/meminfo');
	preg_match('/MemTotal\s*\:\s*(\d+)/i', $file, $matches);
	$server['ram'] = (int)$matches[1];
	preg_match_all('/^(\/\S+)\s+(\S+)\s.*$/m', file_get_contents('/etc/mtab'), $matches);
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
	$server['raid_status'] = trim(`/root/cpaneldirect/check_raid.sh --check=WARNING 2>/dev/null`);
	if (!file_exists('/usr/bin/iostat'))
	{
		echo "Installing iostat..";
		if (trim(`which yum;`) != '')
		{
			echo "CentOS Detected...";
			`yum -y install sysstat;`;
		}
		elseif (trim(`which apt-get;`) != '')
		{
			echo "Ubuntu Detected...";
			`apt-get -y install sysstat;`;
//                `echo -e 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades;`;
		}
		echo "done\n\n";
		if (!file_exists('/usr/bin/iostat'))
		{
			echo "Error installing iostat\n";
		}
	}
	if (file_exists('/usr/bin/iostat'))
	{
		$server['iowait'] = trim(`iostat -c  |grep -v "^$" | tail -n 1 | awk '{ print $4 }';`);
	}
	$cmd = 'if [ "$(which ioping 2>/dev/null)" = "" ]; then 
  if [ -e /usr/bin/apt-get ]; then 
	apt-get update; 
	apt-get install -y ioping; 
  else
	if [ "$(which rpmbuild 2>/dev/null)" = "" ]; then 
	  yum install -y rpm-build; 
	fi;
	if [ "$(which make 2>/dev/null)" = "" ]; then 
	  yum install -y make;
	fi;
	if [ ! -e /usr/include/asm/unistd.h ]; then
	  yum install -y kernel-headers;
	fi;
	wget http://mirror.trouble-free.net/tf/SRPMS/ioping-0.9-1.el6.src.rpm -O ioping-0.9-1.el6.src.rpm; 
	export spec="/$(rpm --install ioping-0.9-1.el6.src.rpm --nomd5 -vv 2>&1|grep spec | cut -d\; -f1 | cut -d/ -f2-)"; 
	rpm --upgrade $(rpmbuild -ba $spec |grep "Wrote:.*ioping-0.9" | cut -d" " -f2); 
	rm -f ioping-0.9-1.el6.src.rpm; 
  fi; 
fi;';
	`$cmd`;
	$cmd = 'if [ "$(which vzctl 2>/dev/null)" = "" ]; then 
  iodev="/$(pvdisplay -c |grep -v -e centos -e backup -e vz-snap |grep :|cut -d/ -f2- |cut -d: -f1|head -n 1)";
else 
  iodev=/vz; 
fi; 
ioping -c 3 -s 100m -D -i 0 ${iodev} -B | cut -d" " -f2;';
//ioping -q -i 0 -w 3 -s 100m -S 100m -B ${iodev} | cut -d" " -f4;';
//ioping -q -i 0 -w 3 -s 10m -S 100m -B ${iodev} | cut -d" " -f4;';
//ioping -B -R ${iodev} | cut -d" " -f4;';
//ioping -c 3 -s 100m -D -i 0 ${iodev} -B | cut -d" " -f6;';
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
			//$totalg = trim(`lvdisplay --units g /dev/vz/thin |grep 'LV Size' | sed s#"^.*LV Size"#""#g | sed s#"GiB"#""#g | sed s#" "#""#g | cut -d\. -f1`);
			//$freeg = trim(`echo "\$(lvdisplay --units g /dev/vz/thin |grep 'LV Size' | sed s#"LV Size"#""#g | sed s#"GiB"#""#g) - ( \$(lvdisplay --units g /dev/vz/thin |grep 'Allocated .*data' | sed s#"Allocated.*data"#""#g |sort -nr| head -n1 |sed s#"%"#""#g) / 100 * \$(lvdisplay --units g /dev/vz/thin |grep 'LV Size' | sed s#"LV Size"#""#g | sed s#"GiB"#""#g) )" |bc -l |cut -d\. -f1`);
			// this one doubles the space usage to make it stop at 50%
			//$freeg = trim(`echo "\$(lvdisplay --units g /dev/vz/thin |grep 'LV Size' | sed s#"LV Size"#""#g | sed s#"GiB"#""#g) - ( \$(lvdisplay --units g /dev/vz/thin |grep 'Allocated .*data' | sed s#"Allocated.*data"#""#g |sort -nr| head -n1 |sed s#"%"#""#g) / 100 * \$(lvdisplay --units g /dev/vz/thin |grep 'LV Size' | sed s#"LV Size"#""#g | sed s#"GiB"#""#g) * 2 )" |bc -l |cut -d\. -f1`);
			$TOTAL_GB = '$(lvdisplay --units g /dev/vz/thin |grep "LV Size" | sed s#"^.*LV Size"#""#g | sed s#"GiB"#""#g | sed s#" "#""#g | cut  -d\. -f1)';
			$USED_PCT = '$(lvdisplay --units g /dev/vz/thin |grep "Allocated .*data" | sed s#"Allocated.*data"#""#g |sort -nr| head -n1 |sed s#"%"#""#g)';
			$GB_PER_PCT = '$(echo "'.$TOTAL_GB.' / 100" |bc -l | cut -d\. -f1)';
			$USED_GB = '$(echo "'.$USED_PCT.' * '.$GB_PER_PCT.'" | bc -l)';
			$MAX_PCT =  60;
			$FREE_PCT = '$(echo "'.$MAX_PCT.' - '.$USED_PCT.'" |bc -l)';
			$FREE_GB = '$(echo "'.$GB_PER_PCT.' * '.$FREE_PCT.'" |bc -l)';
			//echo 'Total GB '.$TOTAL_GB.'Used % '.$USED_PCT.'GB Per % '.$GB_PER_PCT.'USED GB  '.$USED_GB.'MAX % '.$MAX_PCT.'FREE PCT '.$FREE_PCT.'FREE GB '.$FREE_GB;
			//$parts= explode("\n", trim(`$cmd`));
			$totalg = trim(`echo $TOTAL_GB;`);
			$freeg = trim(`echo $FREE_GB;`);
			$out = $totalg.' '.$freeg;
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
	$cmd = 'curl --connect-timeout 60 --max-time 600 -k -d action=vps_info -d servers="'.urlencode(base64_encode(serialize($server))).'" "'.$url.'" 2>/dev/null;';
	// echo "CMD: $cmd\n";
	echo trim(`$cmd`);
	if (file_exists('/usr/sbin/vzctl'))
	{
		if (!file_exists('/proc/user_beancounters'))
		{
			$headers = "MIME-Version: 1.0\n";
			$headers .= "Content-type: text/html; charset=UTF-8\n";
			$headers .= "From: ".`hostname -s`." <hardware@interserver.net>\n";
			mail('hardware@interserver.net', 'OpenVZ server does not appear to be booted properly', 'This server does not have /proc/user_beancounters, was it booted into the wrong kernel?', $headers);

		}
	}
}

update_vps_info();
