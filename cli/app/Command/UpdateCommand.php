<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class UpdateCommand extends Command {
	public function brief() {
		return "Updates a Virtual Machine setting HD, Ram, CPU, Cgroups.";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
        $opts->add('h|hd:', 'HD Size in GB')->isa('number');
        $opts->add('r|ram:', 'Ram Size in MB')->isa('number');
        $opts->add('c|cpu:', 'Number of CPU/Cores')->isa('number');
        $opts->add('g|cgroups:', 'Update CGroups to number of slices')->isa('number');
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
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
		$updateHd = array_key_exists('hd', $opts->keys);
		$updateCpu = array_key_exists('cpu', $opts->keys);
		$updateRam = array_key_exists('ram', $opts->keys);
		$updateCgroups = array_key_exists('cgroups', $opts->keys);
		if ($updateCpu === true || $updateRam === true)
			Vps::runCommand("virsh dumpxml > {$hostname}.xml;");
		if ($updateCpu === true || $updateRam === true || $updateHd === true)
			Vps::stopVps($hostname);
		if ($updateHd === true) {
			$hd = $opts->keys['hd']->value;
			$hd = $hd * 1024;
			$pool = Vps::getPoolType();
			if ($pool == 'zfs') {
				$this->getLogger()->info('Attempting to set ZFS volume size to '.$hd.'MB');
				echo Vps::runCommand("zfs set volsize={$hd}M vz/{$hostname}");
				$this->getLogger()->info('Attempting to resize qcow2 image to '.$hd.'MB');
				echo Vps::runCommand("qemu-img resize /vz/{$hostname}/os.qcow2 {$hd}M");
			} else {
				$this->getLogger()->info('Attempting to resize LVM volume to '.$hd.'MB');
				echo Vps::runCommand("sh /root/cpaneldirect/vps_kvm_lvmresize.sh {$hostname} {$hd}");
			}
		}
		if ($updateCpu === true) {
			$cpu = $opts->keys['cpu']->value;
			$maxCpu = $cpu > 8 ? $cpu : 8;
    		$this->getLogger()->debug('Setting CPU limits');
    		echo Vps::runCommand("sed s#\"<\(vcpu.*\)>.*</vcpu>\"#\"<vcpu placement='static' current='{$cpu}'>{$maxCpu}</vcpu>\"#g -i {$hostname}.xml;");
		}
		if ($updateRam === true) {
			$ram = $opts->keys['ram']->value;
			$ram = $ram * 1024;
    		$maxRam = $ram > 16384000 ? $ram : 16384000;
    		$this->getLogger()->debug('Setting Max Memory limits');
			echo Vps::runCommand("sed s#\"<memory.*memory>\"#\"<memory unit='KiB'>{$maxRam}</memory>\"#g -i {$hostname}.xml;");
			$this->getLogger()->debug('Setting Memory limits');
			echo Vps::runCommand("sed s#\"<currentMemory.*currentMemory>\"#\"<currentMemory unit='KiB'>{$ram}</currentMemory>\"#g -i {$hostname}.xml;");
		}
		if ($updateCpu === true || $updateRam === true) {
			echo Vps::runCommand("virsh define {$hostname}.xml;");
			echo Vps::runCommand("rm -f {$hostname}.xml");
		}
		if ($updateCpu === true || $updateRam === true || $updateHd === true)
			Vps::startVps($hostname);
		if ($updateCgroups === true) {
			$slices = $opts->keys['cgroups']->value;
			Vps::setupCgroups($hostname, $slices);
		}
	}
}
