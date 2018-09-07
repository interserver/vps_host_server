<?php
return function ($stdObject, $params) {
	$cpu = array();
	$files = [];
	if (file_exists('/proc/vz/fairsched/cpu.proc.stat')) {
		foreach (glob('/proc/vz/fairsched/*/cpu.proc.stat') as $file) {
			$id = basename(dirname($file));
			if ($id > 0) {
				$files[$id] = $file;
			}
		}
	}
	$files[0] = '/proc/stat';
	foreach ($files as $id => $file) {
		$text = file_get_contents($file);
		if (preg_match_all('/^(cpu[0-9]*)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)$/m', $text, $matches, PREG_SET_ORDER)) {
			$cpu[$id] = array();
			foreach ($matches as $match) {
				$cpu[$id][$match[1]] = $match[2] + $match[3] + $match[4] + $match[5] + $match[6] + $match[7] + $match[8] + $match[9];
			}
		}
	}
	return $data;
};
