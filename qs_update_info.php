#!/usr/bin/php -q 
<?php
	/**
	 * update_qs_info()
	 * 
	 * @return
	 */
	function update_qs_info()
	{
		$root_used = trim(`df -P /| awk '{ print $5 }' |grep % | sed s#"%"#""#g`);
		if ($root_used > 90)
		{
			$hostname = trim(`hostname;`);
			mail('hardware@interserver.net', $root_used . '% Disk Usage on ' . $hostname, $root_used . '% Disk Usage on ' . $hostname);
		}
		$url = 'https://myquickserver2.interserver.net/qs_queue.php';
		$servers = array();
        switch (trim(`uname -p`))
        {
            case 'i686':
                $servers['bits'] = 32;
                break;
            case 'x86_64':
                $servers['bits'] = 64;
                break;
        }
        $servers['load'] = trim(`cat /proc/loadavg | cut -d" " -f1`);
        $servers['ram'] = trim(`free -m | grep Mem: | awk '{ print \$2 }'`);
        $servers['cpu_model'] = trim(`grep "model name" /proc/cpuinfo | head -n1 | cut -d: -f2-`);
        $servers['cpu_mhz'] = trim(`grep "cpu MHz" /proc/cpuinfo | head -n1 | cut -d: -f2-`);
//		$servers['cores'] = trim(`echo \$((\$(lscpu |grep "^Core(s) per socket" | awk '{ print \$4 }') * \$(lscpu |grep "^Socket" | awk '{ print \$2 }')))`);
//		$servers['cores'] = trim(`echo \$((\$(cat /proc/cpuinfo|grep '^physical id' | sort | uniq | wc -l) * \$(grep '^cpu cores' /proc/cpuinfo  | tail -n 1|  awk '{ print \$4 }')))`);
//		$servers['cores'] = trim(`lscpu |grep "^CPU(s)"| awk '{ print $2 }';`);
		$servers['cores'] = trim(`grep '^processor' /proc/cpuinfo |wc -l;`);
		$cmd = 'df --block-size=1G |grep "^/" | grep -v -e "/dev/mapper/" | awk \'{ print $1 ":" $2 ":" $3 ":" $4 ":" $6 }\'
for i in $(pvdisplay -c); do 
  d="$(echo "$i" | cut -d: -f1 | sed s#" "#""#g)";
  blocksize="$(echo "$i" | cut -d: -f8)";
  total="$(echo "$(echo "$i" | cut -d: -f9) * $blocksize / (1024 * 1024)" | bc -l | cut -d\. -f1)";
  free="$(echo "$(echo "$i" | cut -d: -f10) * $blocksize / (1024 * 1024)" | bc -l | cut -d\. -f1)";
  used="$(echo "$(echo "$i" | cut -d: -f11) * $blocksize / (1024 * 1024)" | bc -l | cut -d\. -f1)";
  target="$(echo "$i" | cut -d: -f2)";
  echo "$d:$total:$used:$free:$target";
done
';
		$servers['mounts'] = str_replace("\n", ',', trim(`$cmd`));
		$servers['raid_status'] = trim(`/root/cpaneldirect/check_raid.pl --check=WARNING 2>/dev/null`);
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
//				`echo -e 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades;`;
			}
			echo "done\n\n";
			if (!file_exists('/usr/bin/iostat'))
			{
				echo "Error installing iostat\n";
			}
		}
		if (file_exists('/usr/bin/iostat'))
		{
			$servers['iowait'] = trim(`iostat -c  |grep -v "^$" | tail -n 1 | awk '{ print $4 }';`);
		}
        
		if (file_exists('/usr/sbin/vzctl'))
		{
			$out = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";df -B G /vz | grep -v ^Filesystem | awk '{ print \$2 " " \$4 }' |sed s#"G"#""#g;`);
		}
		else
		{
			$parts = explode(':', trim(`export PATH="\$PATH:/sbin:/usr/sbin"; pvdisplay -c |grep -v -e centos -e backup`));
			$pesize = $parts[7];
			$totalpe = $parts[8];
			$freepe = $parts[9];
			$totalg = ceil($pesize * $totalpe / 1000000);
			$freeg = ceil($pesize * $freepe / 1000000);
			$out = "$totalg $freeg";
		}
		$parts = explode(' ', $out);
		if (sizeof($parts) == 2)
		{
			$servers['hdsize'] = $parts[0];
			$servers['hdfree'] = $parts[1];
			$cmd = 'curl --connect-timeout 60 --max-time 600 -k -d action=qsinfo -d servers="' . urlencode(base64_encode(serialize($servers))) . '" "' . $url . '" 2>/dev/null;';
			// echo "CMD: $cmd\n";
			echo trim(`$cmd`);
		}
	}

	update_qs_info();	
?>
