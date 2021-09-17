<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class ReinstallOsCommand extends Command {
	public function brief() {
		return "ReinstallOss a Virtual Machine.";
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
		if (!Vps::isVpsRunning($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to be powered on.");
			return 1;
		}
		$this->reinstallOsVps($hostname);
	}

/*
export PATH="$PATH:/usr/sbin:/sbin:/bin:/usr/bin:";
{if isset($vps_extra['vnc']) && (int)$vps_extra['vnc'] > 1000}
/root/cpaneldirect/vps_kvm_screenshot_swift.sh {$vps_extra['vnc'] - 5900} {$hostname};
{/if}
virsh destroy {$hostname} 2>/dev/null;
rm -f /etc/xinetd.d/{$hostname};
service xinetd restart 2>/dev/null || /etc/init.d/xinetd restart 2>/dev/null;
virsh autostart --disable {$hostname} 2>/dev/null;
virsh managedsave-remove {$hostname} 2>/dev/null;
virsh undefine {$hostname};
export pool="$(virsh pool-dumpxml vz 2>/dev/null|grep "<pool"|sed s#"^.*type='\([^']*\)'.*$"#"\1"#g)"
if [ "$pool" = "zfs" ]; then
  device="$(virsh vol-list vz --details|grep " {$hostname}[/ ]"|awk '{ print $2 }')";
else
  device="/dev/vz/{$hostname}";
  kpartx -dv $device;
fi
if [ "$pool" = "zfs" ]; then
  virsh vol-delete --pool vz {$hostname}/os.qcow2 2>/dev/null;
  virsh vol-delete --pool vz {$hostname} 2>/dev/null;
  zfs list -t snapshot|grep "/{$hostname}@"|cut -d" " -f1|xargs -r -n 1 zfs destroy -v;
  zfs destroy vz/{$hostname};
  if [ -e /vz/{$hostname} ]; then
    rmdir /vz/{$hostname};
  fi;
else
  lvremove -f $device;
fi

*/
}
