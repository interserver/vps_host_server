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
		$vncPort = Vps::getVncPort($this->hostname);
		if ($vncPort != '' && intval($vncPort) > 1000) {
			$vncPort -= 5900;
			echo `/root/cpaneldirect/vps_kvm_screenshot_swift.sh {$vncPort} {$this->hostname}`;
		}
		Vps::stopVps($hostname);
		Vps::disableAutostart($hostname);
		echo `virsh managedsave-remove {$hostname}`;
		echo `virsh undefine {$hostname}`;
		$pool = Vps::getPoolType();
		if ($pool == 'zfs') {
			echo `zfs list -t snapshot|grep "/{$hostname}@"|cut -d" " -f1|xargs -r -n 1 zfs destroy -v`;
			echo `virsh vol-delete --pool vz {$hostname}`;
			echo `zfs destroy vz/{$hostname}`;
		} else {
			echo `kpartx -dv /dev/vz/{$hostname}`;
			echo `lvremove -f /dev/vz/{$hostname}`;
		}
		$dhcpVps = file_exists('/etc/dhcp/dhcpd.vps') ? '/etc/dhcp/dhcpd.vps' : '/etc/dhcpd.vps';
		echo `sed s#"^host {$hostname} .*$"#""#g -i {$dhcpVps}`;
	}
}
