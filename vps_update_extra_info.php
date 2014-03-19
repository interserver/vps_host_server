#!/usr/bin/php -q
<?php
	/**
	 * update_vps_extra_info()
	 *
	 * @return
	 */
	function update_vps_extra_info()
	{
		$flags = explode(' ', trim(`grep "^flags" /proc/cpuinfo | head -n 1 | cut -d: -f2-;`));
		sort($flags);
		$flagsnew = implode(' ', $flags);
		$flags = $flagsnew;
		unset($flagsnew);
		$url = 'https://myvps2.interserver.net/vps_queue.php';
		$servers = array();
		$servers['cpu_flags'] = $flags;
		$cmd = 'curl --connect-timeout 60 --max-time 240 -k -d action=vpsinfo_extra -d servers="' . urlencode(base64_encode(serialize($servers))) . '" "' . $url . '" 2>/dev/null;';
		// echo "CMD: $cmd\n";
		echo trim(`$cmd`);
	}

	update_vps_extra_info();
?>
