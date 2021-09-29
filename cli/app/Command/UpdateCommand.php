<?php
namespace App\Command;

use App\Vps;
use App\Vps\Kvm;
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
		$opts->add('v|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
        $opts->add('h|hd:', 'HD Size in GB')->isa('number');
        $opts->add('r|ram:', 'Ram Size in MB')->isa('number');
        $opts->add('c|cpu:', 'Number of CPU/Cores')->isa('number');
        $opts->add('g|cgroups:', 'Update CGroups to number of slices')->isa('number');
        $opts->add('p|password:', 'Sets the root/Administrator password')->isa('string');
        $opts->add('u|username:', 'Sets the password for the given username instead of the root/Administrator')->isa('string');
        $opts->add('q|quota:', 'Enable or Disable Quotas setting them to on or off')->isa('string')->validValues(['on', 'off']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('vzid')->desc('VPS id/name to use')->isa('string');
	}

	public function execute($vzid) {
		Vps::init($this->getOptions(), ['vzid' => $vzid]);
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($vzid)) {
			$this->getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		$updateHd = array_key_exists('hd', $opts->keys);
		$updateCpu = array_key_exists('cpu', $opts->keys);
		$updateRam = array_key_exists('ram', $opts->keys);
		$updatePassword = array_key_exists('password', $opts->keys);
		$updateQuota = array_key_exists('quota', $opts->keys);
		$updateCgroups = array_key_exists('cgroups', $opts->keys);
		if ($updateCpu === true || $updateRam === true)
			if (Vps::getVirtType() == 'kvm')
				Vps::runCommand("virsh dumpxml > {$vzid}.xml;");
		if ($updateCpu === true || $updateRam === true || $updateHd === true)
			Vps::stopVps($vzid);
		if ($updateHd === true) {
			$hd = $opts->keys['hd']->value;
			$hd = $hd * 1024;
			if (Vps::getVirtType() == 'kvm') {
				$pool = Vps::getPoolType();
				if ($pool == 'zfs') {
					$this->getLogger()->info('Attempting to set ZFS volume size to '.$hd.'MB');
					echo Vps::runCommand("zfs set volsize={$hd}M vz/{$vzid}");
					$this->getLogger()->info('Attempting to resize qcow2 image to '.$hd.'MB');
					echo Vps::runCommand("qemu-img resize /vz/{$vzid}/os.qcow2 {$hd}M");
				} else {
					$this->getLogger()->info('Attempting to resize LVM volume to '.$hd.'MB');
					echo Vps::runCommand("sh /root/cpaneldirect/vps_kvm_lvmresize.sh {$vzid} {$hd}");
				}
			} elseif (Vps::getVirtType() == 'virtuozzo') {
				echo Vps::runCommand("prlctl set {$vzid} --device-set hdd0 --size {$hd}");
				$hdG = ceil($hd / 1024);
				echo Vps::runCommand("vzctl set {$vzid}  --diskspace {$hdG}G --save");
			}
		}
		if ($updateQuota === true) {
			if (Vps::getVirtType() == 'virtuozzo') {
				$quota = $opts->keys['quota']->value;
				if ($quota == 'on') {
					echo Vps::runCommand("prlctl set {$vzid} --quotaugidlimit 200 --save --setmode restart");
				} elseif ($quota == 'off') {
                    echo Vps::runCommand("prlctl set {$vzid} --quotaugidlimit 0 --save --setmode restart");
				} else {
					$this->getLogger()->error('Invalid Quotas Option, must be on or off');
				}
			}
		}
		if ($updatePassword === true) {
			$username = array_key_exists('username', $opts->keys) ? $opts->keys['username']->value : 'root';
			$username = escapeshellarg($username);
			$password = $opts->keys['password']->value;
			$password = escapeshellarg($password);
			if (Vps::getVirtType() == 'virtuozzo') {
                echo Vps::runCommand("prlctl set {$vzid} --userpasswd {$username}:{$password}");
			}
		}
		if ($updateCpu === true) {
			$cpu = $opts->keys['cpu']->value;
			$maxCpu = $cpu > 8 ? $cpu : 8;
    		$this->getLogger()->debug('Setting CPU limits');
			if (Vps::getVirtType() == 'kvm') {
    			echo Vps::runCommand("sed s#\"<\(vcpu.*\)>.*</vcpu>\"#\"<vcpu placement='static' current='{$cpu}'>{$maxCpu}</vcpu>\"#g -i {$vzid}.xml;");
    		} elseif (Vps::getVirtType() == 'virtuozzo') {
				echo Vps::runCommand("prlctl set {$vzid} --cpus {$cpu}");
				$cpuUnits = 1500 * $cpu;
				echo Vps::runCommand("prlctl set {$vzid} --cpuunits {$cpuUnits}");
			}
		}
		if ($updateRam === true) {
			$ram = $opts->keys['ram']->value;
			$ram = $ram * 1024;
    		$maxRam = $ram > 16384000 ? $ram : 16384000;
    		$this->getLogger()->debug('Setting Max Memory limits');
			if (Vps::getVirtType() == 'kvm') {
				echo Vps::runCommand("sed s#\"<memory.*memory>\"#\"<memory unit='KiB'>{$maxRam}</memory>\"#g -i {$vzid}.xml;");
				$this->getLogger()->debug('Setting Memory limits');
				echo Vps::runCommand("sed s#\"<currentMemory.*currentMemory>\"#\"<currentMemory unit='KiB'>{$ram}</currentMemory>\"#g -i {$vzid}.xml;");
			} elseif (Vps::getVirtType() == 'virtuozzo') {
				$ramM = ceil($ram / 1024);
				echo Vps::runCommand("prlctl set {$vzid} --swappages 1G --memsize {$ramM}M");
			}
		}
		if ($updateCpu === true || $updateRam === true) {
			if (Vps::getVirtType() == 'kvm') {
				echo Vps::runCommand("virsh define {$vzid}.xml;");
				echo Vps::runCommand("rm -f {$vzid}.xml");
			}
		}
		if ($updateCpu === true || $updateRam === true || $updateHd === true)
			Vps::startVps($vzid);
		if ($updateCgroups === true) {
			$slices = $opts->keys['cgroups']->value;
			if (Vps::getVirtType() == 'kvm')
				Kvm::setupCgroups($vzid, $slices);
		}
	}
}
