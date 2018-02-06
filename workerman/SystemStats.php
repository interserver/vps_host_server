<?php

class SystemStats {

	public static function kernel() {
		return trim(shell_exec('uname -r'));
	}

	public static function hostname() {
		return trim(shell_exec('hostname'));
	}

	public static function distro() {
		$info = trim(shell_exec('cat /etc/*-release'));
		if (preg_match('/DISTRIB_DESCRIPTION="(.*?)"/', $info, $m)) {
			return trim($m[1]);
		} else {
			$lines = explode("\n", $info);
			if (sizeof($lines) == 1)
				return $info;
			else
				return "N/A";
		}
	}

	public static function top_ram() {
		$info = trim(shell_exec('ps aux | sort -b -r -g -k 4 | head -3 | awk \'{print $4"%",$11}\''));
		$new_info = '';
		foreach (explode("\n", $info) as $line) {
			$words = explode(" ", $line);
			$new_info .= array_shift($words);
			if (strpos($words[0], '/') !== false) {
				$folders = explode("/", $words[0]);
				$new_info .= ' ' . array_pop($folders) . "\n";
			} else {
				$new_info .= ' ' . $words[0] . "\n";
			}
		}
		return trim($new_info);
	}

	public static function top_cpu() {
		$info = trim(shell_exec('ps aux | sort -b -r -g -k 3 | head -3 | awk \'{print $3"%",$11}\''));
		$new_info = '';
		foreach (explode("\n", $info) as $line) {
			$words = explode(" ", $line);
			$new_info .= array_shift($words);
			if (strpos($words[0], '/') !== false) {
				$folders = explode("/", $words[0]);
				$new_info .= ' ' . array_pop($folders) . "\n";
			} else {
				$new_info .= ' ' . $words[0] . "\n";
			}
		}
		return trim($new_info);
	}

	public static function top_both() {
		return shell_exec("ps aux | sort -b -r -k 3 -k 4 | head -6 | tail -5 | awk '{print $3,$4,$11}'");
	}


	public static function uptime() {
		$info = trim(shell_exec('uptime'));
		preg_match('/(\d{1,2}:\d{1,2}), /', $info, $m);
		return trim($m[1]);
	}

	/**
	 * Gets system uptime
	 *
	 * @return string	uptime
	 */
	public static function uptime_from_proc() {
		if ($result = rtrim(file_get_contents('/proc/uptime'))) {
			$ar_buf = explode(' ', $result[0]);

			$sys_ticks = trim($ar_buf[0]);

			$min = $sys_ticks / 60;
			$hours = $min / 60;
			$days = floor($hours / 24);
			$hours = floor($hours - ($days * 24));
			$min = floor($min - ($days * 60 * 24) - ($hours * 60));

			$result = array($days, $hours, $min);
		} else {
			$result = 'N.A.';
		}
		return $result;
	}


	public static function all_processes() {
		return trim(shell_exec('ps auxh | wc -l'));
	}

	public static function running_processes() {
		return trim(shell_exec('ps -e | grep -v ? | tail -n +2 | wc -l'));
	}

	public static function cpu_freq() {
		$info = trim(shell_exec('cat /proc/cpuinfo | grep MHz | awk \'{print $4}\' | head -1'));
		// Returns frequency in gigahertz
		return round($info / 1000, 2) . " GHz";
	}

	public static function cpu_cores() {
		$info = trim(shell_exec('cat /proc/cpuinfo'));
		return count(explode("\n\n", $info)) - 1;
	}

	public static function cpu_model() {
		$info = trim(shell_exec('cat /proc/cpuinfo | grep "model name" | head -1'));
		preg_match('/: (.*)/', $info, $m);
		return trim(str_replace('CPU', '', $m[1]));
	}

	public static function cpu_temp() {
		// Get temp from ´sensors´
		$info = trim(shell_exec('sensors'));
		preg_match('/:\s+([+|-].*?)\s*?\(/', $info, $m);
//		$info = $m[1];
		if (!$info) {
			// Get temp from thermal
			$info = shell_exec('cat /sys/class/thermal/thermal_zone0/temp');
			$info = $info / 1000;
		}
		if (!$info) {
			return "N/A";
		} else {
			return trim($info);
		}
	}

	// Alternative: top -bn1 | grep "Cpu(s)" | sed "s/.*, *\([0-9.]*\)%\id.*/\1/" | awk '{print 100 - $1"%"}'

	public static function cpu_load_perc_free() {
		return (int) trim(shell_exec("vmstat | tail -1 | awk '{print $15}'"));
	}

	public static function cpu_load_perc_used() {
		return 100 - self::cpu_load_perc_free();
	}
	public static function ram_total() {
		$info = trim(shell_exec('cat /proc/meminfo | grep MemTotal: | awk \'{print $2}\''));
		// Returns size in gigabytes
		return round($info / pow(1024,2), 2) . " GiB";
	}
	public static function ram_free() {
		$info = trim(shell_exec('cat /proc/meminfo | grep MemFree: | awk \'{print $2}\''));
		// Returns size in gigabytes
		return round($info / pow(1024,2), 2) . " GiB";
	}
	public static function ram_used() {
		// Returns size in megabytes
		// Need to remove the prefix before minus
		return substr(self::ram_total(), 0, -4) - substr(self::ram_free(), 0, -4) . " GiB";
	}

	public static function gfx_model() {
		$info = trim(shell_exec('lspci | grep -i vga'));
		preg_match('/: (.*?) \(/', $info, $m);
//		return trim($m[1]);
	}


	/* from prober */

	public static function cpuinfo() {
		$cpuinfo = file('/proc/cpuinfo');
		foreach ($cpuinfo as $line) {
			$templine = str_replace("\t", '', $line);
			$templine = str_replace("\n", '', $templine);
			$cpuinfo[] = $templine;
		}

		$cpu = array();
		foreach ($cpuinfo as $line) {
			$cpu[] = explode(':', $line);
		}

		$json = str_replace('\t', '', json_encode($cpu));
		$json = str_replace('\n', '', $json);
		$cpuinfo = json_decode($json, true);
		unset($json, $cpu);
		$cpu = array();
		foreach($cpuinfo as $array) {
			$name = $array[0];
			if($name == '') continue;
			$val = ltrim($array[1]);
			$cpu[$name] = $val;
		}
		return $cpu;
	}

	public static function loadavg() {
		if(!is_readable('/proc/loadavg')) return false;
		$loadavg = explode(' ', file_get_contents('/proc/loadavg'));
		return implode(' ', current(array_chunk($loadavg, 4)));
	}

	/**
	 * Gets processor load
	 *
	 * @return array	1/5/15 min load
	 */
	public static function loadavg_from_proc() {
		if ($results = rtrim(file_get_contents('/proc/loadavg'))) {
			$results = explode(' ', $results[0]);
		} else {
			$results = 'N.A.';
		}

		return $results;
	}

	public static function getcores() {
		if(!is_readable('/proc/cpuinfo')) return str_replace("\n",'',`nproc`);
		$cpuinfo = file('/proc/cpuinfo');
		$cores = 0;
		for($i=0;$i<count($cpuinfo);$i++) {
			$linenow = $cpuinfo[$i];
			if(strstr($linenow,'flags')) $cores++;
		}
		if($cores>0) {
			return $cores;
		} else {
			return str_replace("\n",'',`nproc`);
		}
	}

	public static function ramuse() {
		if(!is_readable('/proc/meminfo')) return false;
		$meminfo = file_get_contents('/proc/meminfo');
		$res['MemTotal'] = preg_match('/MemTotal\s*\:\s*(\d+)/i', $meminfo, $MemTotal) ? (int)$MemTotal[1] : 0;
		$res['MemFree'] = preg_match('/MemFree\s*\:\s*(\d+)/i', $meminfo, $MemFree) ? (int)$MemFree[1] : 0;
		$res['Cached'] = preg_match('/Cached\s*\:\s*(\d+)/i', $meminfo, $Cached) ? (int)$Cached[1] : 0;
		$res['Buffers'] = preg_match('/Buffers\s*\:\s*(\d+)/i', $meminfo, $Buffers) ? (int)$Buffers[1] : 0;
		$res['SwapTotal'] = preg_match('/SwapTotal\s*\:\s*(\d+)/i', $meminfo, $SwapTotal) ? (int)$SwapTotal[1] : 0;
		$res['SwapFree'] = preg_match('/SwapFree\s*\:\s*(\d+)/i', $meminfo, $SwapFree) ? (int)$SwapFree[1] : 0;
		return $res;
	}

	public static function prober_uptime() {
		if(!is_readable('/proc/uptime')) return false;
		$cpucores = self::getcores();
		$uptime = str_replace("\n",'',file_get_contents('/proc/uptime'));
		$uptimearr = explode(' ',$uptime);
		$arr['uptime'] = floor($uptimearr[0]);
		$arr['freetime'] = floor($uptimearr[1]) / $cpucores;
		$cpucores = self::getcores();
		$arr['freepercent'] = round(($arr['freetime'] / $arr['uptime'] * 100) , 2);
		return $arr;
	}

	/* tupa system stats */

	/**
	 * Gets distrobution informations
	 *
	 * @return array	distro info
	 */
	public static function distrofile() {
		$result = 'N.A.';
		$distroFileArr = array('debian_version','SuSE-release','mandrake-release','fedora-release','redhat-release','gentoo-release','slackware-version','eos-version','trustix-release','arch-release');

		foreach ($distroFileArr as $distroFile) {
			if (file_exists('/etc/'. $distroFile)) {
				$buf = rtrim(file_get_contents(('/etc/'. $distroFile)));
				$result = ($distroFile == 'debian_version' ? 'Debian ' : '') . trim($buf[0]);
			}
		}
		return $result;
	}


	public static function distroicon() {
		if (file_exists('/etc/debian_version')) {
			$result = 'Debian.gif';
		} elseif (file_exists('/etc/SuSE-release')) {
			$result = 'Suse.gif';
		} elseif (file_exists('/etc/mandrake-release')) {
			$result = 'Mandrake.gif';
		} elseif (file_exists('/etc/fedora-release')) {
			$result = 'Fedora.gif';
		} elseif (file_exists('/etc/redhat-release')) {
			$result = 'Redhat.gif';
		} elseif (file_exists('/etc/gentoo-release')) {
			$result = 'Gentoo.gif';
		} elseif (file_exists('/etc/slackware-version')) {
			$result = 'Slackware.gif';
		} elseif (file_exists('/etc/eos-version')) {
			$result = 'free-eos.gif';
		} elseif (file_exists('/etc/trustix-release')) {
			$result = 'Trustix.gif';
		} elseif (file_exists('/etc/arch-release')) {
			$result = 'Arch.gif';
		} else {
			$result = 'clear.gif';
		}
		return $result;
	}
	/**
	 * Gets canonical hostname
	 *
	 * @return string	hostname
	 */
	public static function chostname() {
		if ($result = explode("\n", rtrim(file_get_contents('/proc/sys/kernel/hostname')))) {
			$result = gethostbyaddr(gethostbyname($result[0]));
		} else {
			$result = 'N.A.';
		}
		return $result;
	}

	/**
	 * Gets kernel version
	 *
	 * @return string	kernel version
	 */
	public static function kernel_from_proc() {
		if ($result = rtrim(file_get_contents('/proc/version'))) {
			$buf = $result[0];
			if (preg_match('/version (.*?) /', $buf, $ar_buf)) {
				$result = $ar_buf[1];
				if (preg_match('/SMP/', $buf))
					$result .= ' (SMP)';
			} else
				$result = 'N.A.';
		} else
			$result = 'N.A.';
		return $result;
	}


	/* tupa */

	/**
	 * Gets memory / swap informations
	 *
	 * @return array	mem/swap informations
	 */
	public static function memory() {
		if ($output = explode("\n", rtrim(file_get_contents('/proc/meminfo')))) {
			$results['ram'] = array();
			$results['swap'] = array();
			$results['devswap'] = array();
			while (list(,$buf) = each($output))
				if (preg_match('/^MemTotal:\s+(.*)\s*kB/i', $buf, $ar_buf))
					$results['ram']['total'] = $ar_buf[1];
				else if (preg_match('/^MemFree:\s+(.*)\s*kB/i', $buf, $ar_buf))
					$results['ram']['free'] = $ar_buf[1];
				else if (preg_match('/^Cached:\s+(.*)\s*kB/i', $buf, $ar_buf))
					$results['ram']['cached'] = $ar_buf[1];
				else if (preg_match('/^Buffers:\s+(.*)\s*kB/i', $buf, $ar_buf))
					$results['ram']['buffers'] = $ar_buf[1];
				else if (preg_match('/^SwapTotal:\s+(.*)\s*kB/i', $buf, $ar_buf))
					$results['swap']['total'] = $ar_buf[1];
				else if (preg_match('/^SwapFree:\s+(.*)\s*kB/i', $buf, $ar_buf))
					$results['swap']['free'] = $ar_buf[1];
			$results['ram']['shared'] = 0;
			$results['ram']['used'] = $results['ram']['total'] - $results['ram']['free'];
			$results['swap']['used'] = $results['swap']['total'] - $results['swap']['free'];
			if (file_exists('/proc/swaps') && count(file('/proc/swaps')) > 1) {
				$swaps = explode("\n", rtrim(file_get_contents('/proc/swaps')));
				while (list(,$swap) = each($swaps))
					$swapdevs[] = $swap;
				for ($i = 1; $i < (count($swapdevs) - 1); $i++) {
					$ar_buf = preg_split('/\s+/', $swapdevs[$i], 6);
					$results['devswap'][$i - 1] = array();
					$results['devswap'][$i - 1]['dev'] = $ar_buf[0];
					$results['devswap'][$i - 1]['total'] = $ar_buf[2];
					$results['devswap'][$i - 1]['used'] = $ar_buf[3];
					$results['devswap'][$i - 1]['free'] = ($results['devswap'][$i - 1]['total'] - $results['devswap'][$i - 1]['used']);
					$results['devswap'][$i - 1]['percent'] = round(($ar_buf[3] * 100) / $ar_buf[2]);
				}
			} else
				$results['devswap'] = array();
			// I don't like this since buffers and cache really aren't
			// 'used' per say, but I get too many emails about it.
			$results['ram']['t_used'] = $results['ram']['used'];
			$results['ram']['t_free'] = $results['ram']['total'] - $results['ram']['t_used'];
			$results['ram']['percent'] = round(($results['ram']['t_used'] * 100) / $results['ram']['total']);
			$results['swap']['percent'] = $results['swap']['total'] > 0 ? round(($results['swap']['used'] * 100) / $results['swap']['total']) : 0;
		} else {
			$results['ram'] = array();
			$results['swap'] = array();
			$results['devswap'] = array();
		}
		return $results;
	}

}
