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
/root/cpaneldirect/vps_kvm_screenshot_swift.sh {$vps_extra['vnc'] - 5900} {$vps_vzid};
{/if}
virsh destroy {$vps_vzid} 2>/dev/null;
rm -f /etc/xinetd.d/{$vps_vzid};
service xinetd restart 2>/dev/null || /etc/init.d/xinetd restart 2>/dev/null;
virsh autostart --disable {$vps_vzid} 2>/dev/null;
virsh managedsave-remove {$vps_vzid} 2>/dev/null;
virsh undefine {$vps_vzid};
export pool="$(virsh pool-dumpxml vz 2>/dev/null|grep "<pool"|sed s#"^.*type='\([^']*\)'.*$"#"\1"#g)"
if [ "$pool" = "zfs" ]; then
  device="$(virsh vol-list vz --details|grep " {$vps_vzid}[/ ]"|awk '{ print $2 }')";
else
  device="/dev/vz/{$vps_vzid}";
  kpartx -dv $device;
fi
if [ "$pool" = "zfs" ]; then
  virsh vol-delete --pool vz {$vps_vzid}/os.qcow2 2>/dev/null;
  virsh vol-delete --pool vz {$vps_vzid} 2>/dev/null;
  zfs list -t snapshot|grep "/{$vps_vzid}@"|cut -d" " -f1|xargs -r -n 1 zfs destroy -v;
  zfs destroy vz/{$vps_vzid};
  if [ -e /vz/{$vps_vzid} ]; then
    rmdir /vz/{$vps_vzid};
  fi;
else
  lvremove -f $device;
fi

*/

	public function reinstallOsVps($hostname) {
		$this->getLogger()->info('ReinstallOsping the VPS');
		$this->getLogger()->indent();
		$this->getLogger()->info('Sending Softwawre Power-Off');
		echo `/usr/bin/virsh shutdown {$hostname}`;
		$reinstallOsped = false;
		$waited = 0;
		$maxWait = 120;
		$sleepTime = 10;
		$continue = true;
		while ($waited <= $maxWait && $reinstallOsped == false) {
			if (Vps::isVpsRunning($hostname)) {
				$this->getLogger()->info('still running, waiting (waited '.$waited.'/'.$maxWait.' seconds)');
				sleep($sleepTime);
				$waited += $sleepTime;
			} else {
				$this->getLogger()->info('appears to have cleanly shutdown');
				$reinstallOsped = true;
			}
		}
		if ($reinstallOsped === false) {
			$this->getLogger()->info('Sending Hardware Power-Off');
			echo `/usr/bin/virsh destroy {$hostname};`;
		}
		$this->getLogger()->unIndent();
	}
}
