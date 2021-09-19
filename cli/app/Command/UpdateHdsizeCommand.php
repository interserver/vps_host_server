<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class UpdateHdsizeCommand extends Command {
	public function brief() {
		return "Change the HD Size of a Virtual Machine.";
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('hd')->desc('HD Size in GB')->optional()->isa('number');
	}

	public function execute($hostname, $hd) {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		$hd = $hd * 1024;
		Vps::stopVps($hostname);
		$pool = Vps::getPoolType();
		if ($pool == 'zfs') {
			$this->getLogger()->info('Attempting to set ZFS volume size to '.$hd.'MB');
			echo `zfs set volsize={$hd}M vz/{$hostname}`;
			$this->getLogger()->info('Attempting to resize qcow2 image to '.$hd.'MB');
			echo `qemu-img resize /vz/{$hostname}/os.qcow2 {$hd}M`;
		} else {
			$this->getLogger()->info('Attempting to resize LVM volume to '.$hd.'MB');
			echo `sh /root/cpaneldirect/vps_kvm_lvmresize.sh {$hostname} {$hd}`;
		}
		Vps::startVps($hostname);
	}
}
