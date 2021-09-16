<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class ChangeHostnameCommand extends Command {
	public function brief() {
		return "ChangeHostnames a Virtual Machine.";
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
		$this->changeHostnameVps($hostname);
	}

/*
export PATH="$PATH:/usr/sbin:/sbin:/bin:/usr/bin:";
export pool="$(virsh pool-dumpxml vz 2>/dev/null|grep "<pool"|sed s#"^.*type='\([^']*\)'.*$"#"\1"#g)";
virsh destroy {$vps_vzid};
if [ "$pool" = "zfs" ]; then
  zfs rename vz/{$vps_vzid} vz/{$param|escapeshellarg};
else
  lvrename /dev/vz/{$vps_vzid} vz/{$param|escapeshellarg};
fi;
virsh domrename {$vps_vzid} {$param|escapeshellarg};
virsh dumpxml {$param|escapeshellarg} > vps.xml;
sed s#"{$vps_vzid}"#{$param|escapeshellarg}#g -i vps.xml;
virsh define vps.xml;
rm -fv vps.xml;
for i in /etc/dhcpd.vps /etc/dhcp/dhcpd.vps /root/cpaneldirect/vps.ipmap /root/cpaneldirect/vps.mainips /root/cpaneldirect/vps.slicemap /root/cpaneldirect/vps.vncmap; do
  if [ -e $i ]; then
	sed s#"{$vps_vzid}"#{$param|escapeshellarg}#g -i $i;
  fi;
done;
if [ -e /etc/apt ]; then
	systemctl restart isc-dhcp-server 2>/dev/null || service isc-dhcp-server restart 2>/dev/null || /etc/init.d/isc-dhcp-server restart 2>/dev/null;
else
	systemctl restart dhcpd 2>/dev/null || service dhcpd restart 2>/dev/null || /etc/init.d/dhcpd restart 2>/dev/null;
fi;
rm -vf /etc/xinetd.d/{$vps_vzid} /etc/xinetd.d/{$vps_vzid}-spice;
virsh start {$param|escapeshellarg};
./vps_refresh_vnc.sh {$param|escapeshellarg};

*/

	public function changeHostnameVps($hostname) {
		$this->getLogger()->info('ChangeHostnameping the VPS');
		$this->getLogger()->indent();
		$this->getLogger()->info('Sending Softwawre Power-Off');
		echo `/usr/bin/virsh shutdown {$hostname}`;
		$changeHostnameped = false;
		$waited = 0;
		$maxWait = 120;
		$sleepTime = 10;
		$continue = true;
		while ($waited <= $maxWait && $changeHostnameped == false) {
			if (Vps::isVpsRunning($hostname)) {
				$this->getLogger()->info('still running, waiting (waited '.$waited.'/'.$maxWait.' seconds)');
				sleep($sleepTime);
				$waited += $sleepTime;
			} else {
				$this->getLogger()->info('appears to have cleanly shutdown');
				$changeHostnameped = true;
			}
		}
		if ($changeHostnameped === false) {
			$this->getLogger()->info('Sending Hardware Power-Off');
			echo `/usr/bin/virsh destroy {$hostname};`;
		}
		$this->getLogger()->unIndent();
	}
}
