<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class DestroyCommand extends Command {
	public function brief() {
		return "Destroys a Virtual Machine.";
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
	}

	public function execute($hostname) {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		$vncPort = Vps::getVncPort($hostname);
		if ($vncPort != '' && intval($vncPort) > 1000) {
			$vncPort -= 5900;
			echo Vps::runCommand("/root/cpaneldirect/vps_kvm_screenshot_swift.sh {$vncPort} {$hostname}");
		}
		Vps::stopVps($hostname, true);
		Vps::disableAutostart($hostname);
		echo Vps::runCommand("virsh managedsave-remove {$hostname}");
		echo Vps::runCommand("virsh undefine {$hostname}");
		$pool = Vps::getPoolType();
		if ($pool == 'zfs') {
			echo Vps::runCommand("zfs list -t snapshot|grep \"/{$hostname}@\"|cut -d\" \" -f1|xargs -r -n 1 zfs destroy -v");
			echo Vps::runCommand("virsh vol-delete --pool vz/os.qcow2 {$hostname} 2>/dev/null");
			echo Vps::runCommand("virsh vol-delete --pool vz {$hostname}");
			echo Vps::runCommand("zfs destroy vz/{$hostname}");
			if (file_exists('/vz/'.$hostname))
				rmdir('/vz/'.$hostname);
		} else {
			echo Vps::runCommand("kpartx -dv /dev/vz/{$hostname}");
			echo Vps::runCommand("lvremove -f /dev/vz/{$hostname}");
		}
		$dhcpVps = file_exists('/etc/dhcp/dhcpd.vps') ? '/etc/dhcp/dhcpd.vps' : '/etc/dhcpd.vps';
		echo Vps::runCommand("sed s#\"^host {$hostname} .*$\"#\"\"#g -i {$dhcpVps}");
	}
}
