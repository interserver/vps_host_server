<?php
use Workerman\Worker;
use Workerman\Lib\Timer;

return function ($stdObject, $maps) {
	$dir = __DIR__.'/../../../';
	//Worker::safeEcho('Got Map Startup'.PHP_EOL);
	if (isset($maps['mainips'])) {
		$old = file_exists($dir.'vps.mainips') ? trim(file_get_contents($dir.'vps.mainips')) : null;
		if (trim($maps['mainips']) != $old) {
			file_put_contents($dir.'vps.mainips', trim($maps['mainips']));
		}
	}
	if (isset($maps['slices'])) {
		$old = trim(file_get_contents($dir.'vps.slicemap'));
		if (trim($maps['slices']) != $old) {
			file_put_contents($dir.'vps.slicemap', trim($maps['slices']));
		}
	}
	if (isset($maps['ips'])) {
		$old = trim(file_get_contents($dir.'vps.ipmap'));
		if (trim($maps['ips']) != $old) {
			file_put_contents($dir.'vps.ipmap', trim($maps['ips']));
			Worker::safeEcho(exec($dir.'run_buildebtables.sh'));
		}
	}
	if (isset($maps['vnc'])) {
		$old = trim(file_get_contents($dir.'vps.vncmap'));
		if (trim($maps['vnc']) != $old) {
			file_put_contents($dir.'vps.vncmap', trim($maps['vnc']));
			$lines = explode("\n", trim(exec('virsh list --name')));
			foreach ($lines as $vps) {
				if (preg_match("/^(.*{$vps}):(.*)$/m", $maps['vnc'], $matches)) {
					if (!file_exists('/etc/xinetd.d/'.$matches[0])) {
						Worker::safeEcho(exec("sh {$dir}vps_kvm_setup_vnc.sh {$matches[0]} {$matches[1]}"));
					}
				}
			}
		}
	}
	//Worker::safeEcho('Got Map Calling vps_get_list'.PHP_EOL);
	$stdObject->vps_get_list();
	//Worker::safeEcho('Got Map End'.PHP_EOL);
};
