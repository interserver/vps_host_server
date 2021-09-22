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
		$args->add('hostname')->desc('Current Hostname')->isa('string');
		$args->add('newname')->desc('New Hostname')->isa('string');
	}

	public function execute($hostname, $newname) {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if (Vps::vpsExists($newname)) {
			$this->getLogger()->error("The VPS '{$newname}' you specified already exists so we cannot rename '{$hostname}' to it.");
			return 1;
		}
		$pool = Vps::getPoolType();
		Vps::stopVps($hostname);
		if ($pool == 'zfs') {
			echo Vps::runCommand("zfs rename vz/{$hostname} vz/{$newname}");
		} else {
			echo Vps::runCommand("lvrename /dev/vz/{$hostname} vz/{$newname}");
		}
		echo Vps::runCommand("virsh domrename {$hostname} {$newname}");
		echo Vps::runCommand("virsh dumpxml {$newname} > /root/cpaneldirect/vps.xml");
		echo Vps::runCommand("sed s#\"{$hostname}\"#{$newname}#g -i /root/cpaneldirect/vps.xml");
		echo Vps::runCommand("virsh define /root/cpaneldirect/vps.xml");
		echo Vps::runCommand("rm -fv /root/cpaneldirect/vps.xml");
		foreach (['/etc/dhcpd.vps', '/etc/dhcp/dhcpd.vps', '/root/cpaneldirect/vps.ipmap', '/root/cpaneldirect/vps.mainips', '/root/cpaneldirect/vps.slicemap', '/root/cpaneldirect/vps.vncmap'] as $file) {
			if (file_exists($file)) {
				$data = file_get_contents($file);
				$data = str_replace($hostname, $newname, $data);
				file_put_contents($file, $data);
			}
		}
		if (file_exists('/etc/apt')) {
			echo Vps::runCommand("systemctl restart isc-dhcp-server 2>/dev/null || service isc-dhcp-server restart 2>/dev/null || /etc/init.d/isc-dhcp-server restart 2>/dev/null");
		} else {
			echo Vps::runCommand("systemctl restart dhcpd 2>/dev/null || service dhcpd restart 2>/dev/null || /etc/init.d/dhcpd restart 2>/dev/null");
		}
		echo Vps::runCommand("rm -vf /etc/xinetd.d/{$hostname} /etc/xinetd.d/{$hostname}-spice");
		echo Vps::runCommand("virsh start {$newname}");
		echo Vps::runCommand("/root/cpaneldirect/vps_refresh_vnc.sh {$newname}");
	}

}
