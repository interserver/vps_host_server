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
		$pool = Vps::getPoolType();
		Vps::stopVps($hostname);
/*
if [ "$pool" = "zfs" ]; then
  zfs rename vz/{$hostname} vz/{$param|escapeshellarg};
else
  lvrename /dev/vz/{$hostname} vz/{$param|escapeshellarg};
fi;
virsh domrename {$hostname} {$param|escapeshellarg};
virsh dumpxml {$param|escapeshellarg} > vps.xml;
sed s#"{$hostname}"#{$param|escapeshellarg}#g -i vps.xml;
virsh define vps.xml;
rm -fv vps.xml;
for i in /etc/dhcpd.vps /etc/dhcp/dhcpd.vps /root/cpaneldirect/vps.ipmap /root/cpaneldirect/vps.mainips /root/cpaneldirect/vps.slicemap /root/cpaneldirect/vps.vncmap; do
  if [ -e $i ]; then
	sed s#"{$hostname}"#{$param|escapeshellarg}#g -i $i;
  fi;
done;
if [ -e /etc/apt ]; then
	systemctl restart isc-dhcp-server 2>/dev/null || service isc-dhcp-server restart 2>/dev/null || /etc/init.d/isc-dhcp-server restart 2>/dev/null;
else
	systemctl restart dhcpd 2>/dev/null || service dhcpd restart 2>/dev/null || /etc/init.d/dhcpd restart 2>/dev/null;
fi;
rm -vf /etc/xinetd.d/{$hostname} /etc/xinetd.d/{$hostname}-spice;
virsh start {$param|escapeshellarg};
./vps_refresh_vnc.sh {$param|escapeshellarg};
*/
	}

}
