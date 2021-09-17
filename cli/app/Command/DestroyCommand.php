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
	}

/*
virsh managedsave-remove {$vps_vzid};
virsh undefine {$vps_vzid};
export pool="$(virsh pool-dumpxml vz 2>/dev/null|grep "<pool"|sed s#"^.*type='\([^']*\)'.*$"#"\1"#g)"
if [ "$pool" = "zfs" ]; then
  zfs list -t snapshot|grep "/{$vps_vzid}@"|cut -d" " -f1|xargs -r -n 1 zfs destroy -v
  virsh vol-delete --pool vz {$vps_vzid};
  zfs destroy vz/{$vps_vzid};
else
  kpartx -dv /dev/vz/{$vps_vzid};
  lvremove -f /dev/vz/{$vps_vzid};
fi
if [ -e /etc/dhcp/dhcpd.vps ]; then
  sed s#"^host {$vps_vzid} .*$"#""#g -i /etc/dhcp/dhcpd.vps;
else
  sed s#"^host {$vps_vzid} .*$"#""#g -i /etc/dhcpd.vps;
fi;

*/
}
