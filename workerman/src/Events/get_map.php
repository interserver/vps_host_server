<?php
use Workerman\Lib\Timer;

return function($stdObject, $maps) {
	if (isset($maps['mainips'])) {
		$old = file_exists('/root/cpaneldirect/vps.mainips') ? trim(file_get_contents('/root/cpaneldirect/vps.mainips')) : null;
		if (trim($maps['mainips']) != $old)
			file_put_contents('/root/cpaneldirect/vps.mainips', trim($maps['mainips']));
	}
	if (isset($maps['slices'])) {
	$old = trim(file_get_contents('/root/cpaneldirect/vps.slicemap'));
	if (trim($maps['slices']) != $old)
		file_put_contents('/root/cpaneldirect/vps.slicemap', trim($maps['slices']));
	}
	if (isset($maps['ips'])) {
		$old = trim(file_get_contents('/root/cpaneldirect/vps.ipmap'));
		if (trim($maps['ips']) != $old) {
			file_put_contents('/root/cpaneldirect/vps.ipmap', trim($maps['ips']));
			echo exec('/root/cpaneldirect/run_buildebtables.sh');        
		}
	}
	if (isset($maps['vnc'])) {
		$old = trim(file_get_contents('/root/cpaneldirect/vps.vncmap'));
		if (trim($maps['vnc']) != $old) {
			file_put_contents('/root/cpaneldirect/vps.vncmap', trim($maps['vnc']));
			$lines = explode("\n", trim(exec('virsh list --name')));
			foreach ($lines as $vps)
				if (preg_match("/^(.*{$vps}):(.*)$/m", $maps['vnc'], $matches))
					if (!file_exists('/etc/xinetd.d/'.$matches[0]))
						exec("sh /root/cpaneldirect/vps_kvm_setup_vnc.sh {$matches[0]} {$matches[1]}");
		}
	}
	$stdObject->vps_get_list();
};
