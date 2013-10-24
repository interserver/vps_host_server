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
		$servers['cores'] = trim(`echo \$((\$(cat /proc/cpuinfo|grep '^physical id' | sort | uniq | wc -l) * \$(grep '^cpu cores' /proc/cpuinfo  | tail -n 1|  awk '{ print \$4 }')))`);
        
		if (file_exists('/usr/sbin/vzctl'))
		{
			$out = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";df -B G /vz | grep -v ^Filesystem | awk '{ print \$2 " " \$4 }' |sed s#"G"#""#g;`);
		}
		else
		{
			$parts = explode(':', trim(`export PATH="\$PATH:/sbin:/usr/sbin"; pvdisplay -c`));
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
			$cmd = 'curl --connect-timeout 60 --max-time 240 -k -d action=qsinfo -d servers="' . urlencode(base64_encode(serialize($servers))) . '" "' . $url . '" 2>/dev/null;';
			// echo "CMD: $cmd\n";
			echo trim(`$cmd`);
		}
	}

	update_qs_info();	
?>
