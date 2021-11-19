<?php
namespace App\Command;

use App\Vps;
use App\Vps\Kvm;
use App\Os\Dhcpd;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class ChangeHostnameCommand extends Command {
	public function brief() {
		return "ChangeHostnames a Virtual Machine.";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('vzid')->desc('VPS id/name to use')->isa('string')->validValues([Vps::class, 'getAllVpsAllVirts']);
		//$args->add('hostname')->desc('New Hostname')->isa('string');
		$args->add('newname')->desc('New Hostname')->isa('string');
	}

	public function execute($vzid, $newname) {
		Vps::init($this->getOptions(), ['vzid' => $vzid, 'newname' => $newname]);
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($vzid)) {
			$this->getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if (Vps::vpsExists($newname)) {
			$this->getLogger()->error("The VPS '{$newname}' you specified already exists so we cannot rename '{$vzid}' to it.");
			return 1;
		}
		$base = Vps::$base;
		if (Vps::getVirtType() == 'kvm') {
			$pool = Vps::getPoolType();
			Vps::stopVps($vzid);
			if ($pool == 'zfs') {
				Vps::getLogger()->write(Vps::runCommand("zfs rename vz/{$vzid} vz/{$newname}"));
			} else {
				Vps::getLogger()->write(Vps::runCommand("lvrename /dev/vz/{$vzid} vz/{$newname}"));
			}
			Vps::getLogger()->write(Vps::runCommand("virsh domrename {$vzid} {$newname}"));
			Vps::getLogger()->write(Vps::runCommand("virsh dumpxml {$newname} > {$base}/vps.xml"));
			Vps::getLogger()->write(Vps::runCommand("sed s#\"{$vzid}\"#{$newname}#g -i {$base}/vps.xml"));
			Vps::getLogger()->write(Vps::runCommand("virsh define {$base}/vps.xml"));
			Vps::getLogger()->write(Vps::runCommand("rm -fv {$base}/vps.xml"));
		}
		foreach (['/etc/dhcpd.vps', '/etc/dhcp/dhcpd.vps', $base.'/vps.ipmap', $base.'/vps.mainips', $base.'/vps.slicemap', $base.'/vps.vncmap'] as $file) {
			if (file_exists($file)) {
				$data = file_get_contents($file);
				$data = str_replace($vzid, $newname, $data);
				file_put_contents($file, $data);
			}
		}
		Xinetd::remove($vzid);
		if (Vps::getVirtType() == 'kvm') {
			Dhcpd::restart();
		} elseif (Vps::getVirtType() == 'virtuozzo') {
			Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --hostname {$newname}"));
		}
		Vps::startVps($newname);
		Vps::getLogger()->write(Vps::runCommand("{$base}/vps_refresh_vnc.sh {$newname}"));

	}

}
