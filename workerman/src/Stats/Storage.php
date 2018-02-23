<?php

class StorageStats {

	public static function fs_root_total() {
		// Returns size in gigabytes
		return trim(shell_exec('df -h | tail -n +2 | head -1 | awk \'{print $2}\' | sed \'$s/.$//\'')) . " GiB";
	}

	public static function fs_root_used() {
		// Returns size in gigabytes
		return trim(shell_exec('df -h | tail -n +2 | head -1 | awk \'{print $3}\' | sed \'$s/.$//\'')) . " GiB";
	}

	public static function fs_root_free() {
		// Returns size in gigabytes
		return trim(shell_exec('df -h | tail -n +2 | head -1 | awk \'{print $4}\' | sed \'$s/.$//\'')) . " GiB";
	}

	public static function fs_root_perc_used() {
		return trim(shell_exec('df -h | tail -n +2 | head -1 | awk \'{print $5}\''));
	}

	public static function fs_root_type() {
		return trim(shell_exec('cat /etc/fstab | grep \'/[^a-z]\' | awk \'{print $3}\''));
	}

	public static function fs_swap_total() {
		$info = trim(shell_exec('/sbin/swapon -s | grep /dev | awk \'{print $3}\''));
		return round($info / 1024) . ' MiB'; // Returns size in megabytes
	}

	public static function fs_swap_used() {
		$info = trim(shell_exec('/sbin/swapon -s | grep /dev | awk \'{print $4}\''));
		return round($info / 1024) . ' MiB'; // Returns size in megabytes
	}

	public static function fs_swap_free() {
		// Returns size in megabytes
		return round(substr(self::fs_swap_total(), 0, -4) - substr(self::fs_swap_used(), 0, -4)) . ' MiB';
	}

	public static function fs_swap_perc_used() {
		// Need to remove prefix before divsion
		return substr(self::fs_swap_free(), 0, -4) / substr(self::fs_swap_total(), 0, -4) * 100;
	}

	public static function fs_swap_swappiness() {
		return trim(shell_exec('cat /proc/sys/vm/swappiness'));
	}

	public static function hddusage() {
		$df = `df`;
		if($df == null) {
			$total = disk_total_space('/');
			$free = disk_free_space('/');
			$used = (int)$total - $free;
			$array['percent'] = floor(($used / $total) * 100);
			$array['used'] = floor($used / 1024);
			$array['total'] = floor($total / 1024);
			return $array;
		} else {
			while(strstr($df,'  ')) $df = str_replace('  ',' ',$df);
			$df = explode("\n",$df);
			$dff = array();
			$t_total = 0;
			$t_used = 0;
			for($i=1;$i<count($df)-1;$i++) {
				$linenow = $df[$i];
				$temp = explode(' ',$linenow);
				$exclude = array('tmpfs','devtmpfs','udev','none','shm');
				$Filesystem = $temp[0];
				$total = $temp[1];
				$used = $temp[2];
				for($x=0;$x<count($exclude);$x++) {
					$keynow = $exclude[$x];
					if($Filesystem == $keynow) continue 2;
				}
				$t_total = $t_total + $total;
				$t_used = $t_used + $used;
			}
			$array['percent'] = floor(($t_used / $t_total) * 100);
			$array['used'] = $t_used;
			$array['total'] = $t_total;
			return $array;
		}
	}

	/**
	 * Gets filesystem informations
	 *
	 * @return array	FS info
	 */
	public static function filesystems() {
		exec('df -kP', $output);
		while (list(,$mount) = each($output))
			$mounts[] = $mount;
		$fstype = array();
		if ($output = explode("\n", rtrim(file_get_contents('/proc/mounts'))))
			while (list(,$buf) = each($output)) {
				list($dev, $mpoint, $type) = preg_split('/\s+/', trim($buf), 4);
				$fstype[$mpoint] = $type;
				$fsdev[$dev] = $type;
			}
		for ($i = 1, $max = count($mounts); $i < $max; $i++) {
			$ar_buf = preg_split('/\s+/', $mounts[$i], 6);
			$results[$i - 1] = array();
			$results[$i - 1]['disk'] = $ar_buf[0];
			$results[$i - 1]['size'] = $ar_buf[1];
			$results[$i - 1]['used'] = $ar_buf[2];
			$results[$i - 1]['free'] = $ar_buf[3];
			$results[$i - 1]['percent'] = round(($results[$i - 1]['used'] * 100) / $results[$i - 1]['size']);
			$results[$i - 1]['mount'] = $ar_buf[5];
			($fstype[$ar_buf[5]]) ? $results[$i - 1]['fstype'] = $fstype[$ar_buf[5]] : $results[$i - 1]['fstype'] = $fsdev[$ar_buf[0]];
		}
		return $results;
	}
}
