<?php
use Workerman\Lib\Timer;

return function ($stdObject, $maps) {
	//echo 'Got Map Startup'.PHP_EOL;
	if (isset($maps['mainips'])) {
		$old = file_exists(__DIR__.'/../../../vps.mainips') ? trim(file_get_contents(__DIR__.'/../../../vps.mainips')) : null;
		if (trim($maps['mainips']) != $old) {
			file_put_contents(__DIR__.'/../../../vps.mainips', trim($maps['mainips']));
		}
	}
	if (isset($maps['slices'])) {
		$old = trim(file_get_contents(__DIR__.'/../../../vps.slicemap'));
		if (trim($maps['slices']) != $old) {
			file_put_contents(__DIR__.'/../../../vps.slicemap', trim($maps['slices']));
		}
	}
	if (isset($maps['ips'])) {
		$old = trim(file_get_contents(__DIR__.'/../../../vps.ipmap'));
		if (trim($maps['ips']) != $old) {
			file_put_contents(__DIR__.'/../../../vps.ipmap', trim($maps['ips']));
			echo exec(__DIR__.'/../../../run_buildebtables.sh');
		}
	}
	if (isset($maps['vnc'])) {
		$old = trim(file_get_contents(__DIR__.'/../../../vps.vncmap'));
		if (trim($maps['vnc']) != $old) {
			file_put_contents(__DIR__.'/../../../vps.vncmap', trim($maps['vnc']));
			$lines = explode("\n", trim(exec('virsh list --name')));
			foreach ($lines as $vps) {
				if (preg_match("/^(.*{$vps}):(.*)$/m", $maps['vnc'], $matches)) {
					if (!file_exists('/etc/xinetd.d/'.$matches[0])) {
						exec("sh /root/cpaneldirect/vps_kvm_setup_vnc.sh {$matches[0]} {$matches[1]}");
					}
				}
			}
		}
	}
	//echo 'Got Map Calling vps_get_list'.PHP_EOL;
	$stdObject->vps_get_list();
	//echo 'Got Map End'.PHP_EOL;
};
