<?php
namespace App\Command;

use App\Vps;
use App\Vps\Kvm;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class UpdateCommand extends Command
{
	public function brief() {
		return "Updates a Virtual Machine setting HD, Ram, CPU, Cgroups.";
	}

	/** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
		$opts->add('h|hd:', 'HD Size in GB')->isa('number');
		$opts->add('r|ram:', 'Ram Size in MB')->isa('number');
		$opts->add('c|cpu:', 'Number of CPU/Cores')->isa('number');
		$opts->add('g|cgroups:', 'Update CGroups to number of slices')->isa('number');
		$opts->add('z|timezone:', 'changes the timezone')->isa('string');
		$opts->add('n|hostname:', 'changes the hostname')->isa('string');
		$opts->add('p|password:', 'Sets the root/Administrator password')->isa('string');
		$opts->add('password-reset', 'Sets the root/Administrator password');
		$opts->add('u|username:', 'Sets the password for the given username instead of the root/Administrator')->isa('string');
		$opts->add('q|quota:', 'Enable or Disable Quotas setting them to on or off')->isa('string')->validValues(['on', 'off']);
	}

	/** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('vzid')->desc('VPS id/name to use')->isa('string')->validValues([Vps::class, 'getAllVpsAllVirts']);
	}

	public function execute($vzid) {
		Vps::init($this->getOptions(), ['vzid' => $vzid]);
		if (!Vps::isVirtualHost()) {
			Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($vzid)) {
			Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		$base = Vps::$base;
		$updateHd = array_key_exists('hd', $opts->keys);
		$updateCpu = array_key_exists('cpu', $opts->keys);
		$updateRam = array_key_exists('ram', $opts->keys);
		$updatePassword = array_key_exists('password', $opts->keys);
		$updatePasswordReset = array_key_exists('password-reset', $opts->keys);
		$updateQuota = array_key_exists('quota', $opts->keys);
		$updateCgroups = array_key_exists('cgroups', $opts->keys);
		$updateTimezone = array_key_exists('timezone', $opts->keys);
		$updateHostname = array_key_exists('hostname', $opts->keys);
		if ($updateCpu === true || $updateRam === true || $updateHd === true || $updateTimezone === true || $updateHostname === true || $updatePassword === true || $updatePasswordReset === true)
			Vps::stopVps($vzid);
		if ($updateHd === true) {
			$hd = $opts->keys['hd']->value;
			$hd = $hd * 1024;
			if (Vps::getVirtType() == 'kvm') {
				$pool = Vps::getPoolType();
				if ($pool == 'zfs') {
					Vps::getLogger()->info('Attempting to set ZFS volume size to '.$hd.'MB');
					Vps::getLogger()->write(Vps::runCommand("zfs set volsize={$hd}M vz/{$vzid}"));
					Vps::getLogger()->info('Attempting to resize qcow2 image to '.$hd.'MB');
					Vps::getLogger()->write(Vps::runCommand("qemu-img resize /vz/{$vzid}/os.qcow2 {$hd}M"));
				} else {
					Vps::getLogger()->info('Attempting to resize LVM volume to '.$hd.'MB');
					Vps::getLogger()->write(Vps::runCommand("sh {$base}/vps_kvm_lvmresize.sh {$vzid} {$hd}"));
				}
			} elseif (Vps::getVirtType() == 'virtuozzo') {
				Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --device-set hdd0 --size {$hd}"));
				$hdG = ceil($hd / 1024);
				Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid}  --diskspace {$hdG}G --save"));
			} elseif (Vps::getVirtType() == 'openvz') {
				$hdG = ceil($hd / 1024);
				Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid}  --diskspace {$hdG}G --save"));
			}
		}
		if ($updateQuota === true) {
			$quota = $opts->keys['quota']->value;
			if ($quota == 'on') {
				if (Vps::getVirtType() == 'virtuozzo') {
					Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --quotaugidlimit 200 --save --setmode restart"));
				} elseif (Vps::getVirtType() == 'openvz') {
					Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --quotaugidlimit 200 --save --setmode restart"));
				}
			} elseif ($quota == 'off') {
				if (Vps::getVirtType() == 'virtuozzo') {
					Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --quotaugidlimit 0 --save --setmode restart"));
				} elseif (Vps::getVirtType() == 'openvz') {
					Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --quotaugidlimit 0 --save --setmode restart"));
				}
			} else {
				Vps::getLogger()->error('Invalid Quotas Option, must be on or off');
			}
		}
		if ($updatePassword === true) {
			$username = array_key_exists('username', $opts->keys) ? $opts->keys['username']->value : 'root';
			$username = escapeshellarg($username);
			$password = $opts->keys['password']->value;
			$password = escapeshellarg($password);
			if (Vps::getVirtType() == 'virtuozzo') {
				Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --userpasswd {$username}:{$password}"));
			} elseif (Vps::getVirtType() == 'openvz') {
				Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --setmode restart --userpasswd {$username}:{$password}"));
			} elseif (Vps::getVirtType() == 'kvm') {
				Vps::getLogger()->write(Vps::runCommand("virt-customize -d {$vzid} --root-password password:{$password};"));
			}
		}
		if ($updateHostname === true) {
			$hostname = $opts->keys['hostname']->value;
			$hostname = escapeshellarg($hostname);
			if (Vps::getVirtType() == 'virtuozzo') {
				Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --hostname {$hostname}"));
			} elseif (Vps::getVirtType() == 'openvz') {
				Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --setmode restart --hostname {$hostname}"));
			} elseif (Vps::getVirtType() == 'kvm') {
				Vps::getLogger()->write(Vps::runCommand("virt-customize -d {$vzid} --hostname {$hostname};"));
			}
		}
		if ($updateCpu === true || $updateRam === true || $updateTimezone === true) {
			if (Vps::getVirtType() == 'kvm')
				Vps::runCommand("virsh dumpxml > {$vzid}.xml;");
		}
		if ($updateCpu === true) {
			$cpu = $opts->keys['cpu']->value;
			$maxCpu = $cpu > 8 ? $cpu : 8;
			Vps::getLogger()->debug('Setting CPU limits');
			if (Vps::getVirtType() == 'kvm') {
				Vps::getLogger()->write(Vps::runCommand("sed s#\"<\(vcpu.*\)>.*</vcpu>\"#\"<vcpu placement='static' current='{$cpu}'>{$maxCpu}</vcpu>\"#g -i {$vzid}.xml;"));
			} elseif (Vps::getVirtType() == 'virtuozzo') {
				$cpuUnits = 1500 * $cpu;
				Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --cpus {$cpu} --cpuunits {$cpuUnits}"));
			} elseif (Vps::getVirtType() == 'openvz') {
				$cpuUnits = 1500 * $cpu;
				Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --cpus {$cpu} --cpuunits {$cpuUnits}"));
			}
		}
		if ($updateRam === true) {
			$ram = $opts->keys['ram']->value;
			$ram = $ram * 1024;
			$maxRam = $ram > 16384000 ? $ram : 16384000;
			Vps::getLogger()->debug('Setting Max Memory limits');
			if (Vps::getVirtType() == 'kvm') {
				Vps::getLogger()->write(Vps::runCommand("sed s#\"<memory.*memory>\"#\"<memory unit='KiB'>{$maxRam}</memory>\"#g -i {$vzid}.xml;"));
				Vps::getLogger()->debug('Setting Memory limits');
				Vps::getLogger()->write(Vps::runCommand("sed s#\"<currentMemory.*currentMemory>\"#\"<currentMemory unit='KiB'>{$ram}</currentMemory>\"#g -i {$vzid}.xml;"));
			} elseif (Vps::getVirtType() == 'virtuozzo') {
				$ramM = ceil($ram / 1024);
				Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --swappages 1G --memsize {$ramM}M"));
			} elseif (Vps::getVirtType() == 'openvz') {
				$ramM = ceil($ram / 1024);

				$slices = $cpu;
				$wiggle = 1000;
				$dCacheWiggle = 400000;
				$avNumProc = 300 * $slices;
				$avNumProcB = $avNumProc;
				$numProc = 250 * $slices;
				$numProcB = $numProc;
				$numFlock = 8200 * $slices;
				$numFlockB = $numFlock;
				$numIptent = 2000 * $slices;
				$numIptentB = $numIptent;
				$numPty = 35 + (24 * $slices);
				$numPtyB = $numPty;
		        $numTcpSock = 1800 + $slices;
		        $numTcpSockB = $numTcpSock;
				$numOtherSock = 1900 * $slices;
				$numOtherSockB = $numOtherSock;
				$numFile = 32 * $avNumProc;
				$numFileB = $numFile;
				$dgramRcvBuf = 2075488 * $slices;
				$dgramRcvBufB = $dgramRcvBuf;
				$tcpRcvBuf = 8958464 * $slices;
				$tcpRcvBufB = (2561 * $numTcpSock) + $tcpRcvBuf;
				$tcpSndBuf = 8958464 * $slices;
				$tcpSndBufB = (2561 * $numTcpSock) + $tcpSndBuf;
				$otherSockBuf = 775488 * $slices;
				$otherSockBufB = (2561 * $numOtherSock) + $otherSockBuf;
				$shmPages = 100000 * $slices;
				$shmPagesB = $shmPages;
				$dCacheSize = 384 * $numFile + $dCacheWiggle;
				$dCacheSizeB = 384 * $numFileB + $dCacheWiggle;
				$vmGuarPages = ((256 * 2048) * $slices) - $wiggle;
				$privVmPages = ((256 * 2048) * $slices);
				$privVmPagesB = $privVmPages + $wiggle;
				$oomGuarPages = $vmGuarPages;
				$kMemSize = (45 * 1024 * $avNumProc + $dCacheSize);
				$kMemSizeB = (45 * 1024 * $avNumProcB + $dCacheSizeB);
				$diskSpace = $hd * 1024;
				$diskSpaceB = $diskSpace;
				$ram = floor($ram / 1024);
				Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save {$force} --numproc {$numProc}:{$numProcB} --numtcpsock {$numTcpSock}:{$numTcpSockB} --numothersock {$numOtherSock}:{$numOtherSockB} --vmguarpages {$vmGuarPages}:{$limit} --kmemsize unlimited:unlimited --tcpsndbuf {$tcpSndBuf}:{$tcpSndBufB} --tcprcvbuf {$tcpRcvBuf}:{$tcpRcvBufB} --othersockbuf {$otherSockBuf}:{$otherSockBufB} --dgramrcvbuf {$dgramRcvBuf}:{$dgramRcvBufB} --oomguarpages {$oomGuarPages}:{$limit} --privvmpages {$privVmPages}:{$privVmPagesB} --numfile {$numFile}:{$numFileB} --numflock {$numFlock}:{$numFlockB} --physpages 0:{$limit} --dcachesize {$dCacheSize}:{$dCacheSizeB} --numiptent {$numIptent}:{$numIptentB} --avnumproc {$avNumProc}:{$avNumProc} --numpty {$numPty}:{$numPtyB} --shmpages {$shmPages}:{$shmPagesB} 2>&1"));
				if (file_exists('/proc/vz/vswap')) {
					Vps::getLogger()->write(Vps::runCommand("/bin/mv -f /etc/vz/conf/{$vzid}.conf /etc/vz/conf/{$vzid}.conf.backup"));
					Vps::getLogger()->write(Vps::runCommand("grep -Ev '^(KMEMSIZE|PRIVVMPAGES)=' > /etc/vz/conf/{$vzid}.conf <  /etc/vz/conf/{$vzid}.conf.backup"));
					Vps::getLogger()->write(Vps::runCommand("/bin/rm -f /etc/vz/conf/{$vzid}.conf.backup"));
					Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --ram {$ram}M --swap {$ram}M --save"));
					Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --reset_ub"));
				}
				if (file_exists('/usr/sbin/vzcfgvalidate')) // validate vps
					Vps::getLogger()->write(Vps::runCommand("/usr/sbin/vzcfgvalidate -r /etc/vz/conf/{$vzid}.conf"));


			}
		}
		if ($updateTimezone === true) {
			$timezone = $opts->keys['timezone']->value;
			Vps::getLogger()->write(Vps::runCommand("sed s#\"<clock.*$\"#\"<clock offset='timezone' timezone='{$timezone}'/>\"#g -i {$vzid}.xml"));
		}
		if ($updateCpu === true || $updateRam === true || $updateTimezone === true) {
			if (Vps::getVirtType() == 'kvm') {
				Vps::getLogger()->write(Vps::runCommand("virsh define {$vzid}.xml;"));
				Vps::getLogger()->write(Vps::runCommand("rm -f {$vzid}.xml"));
			}
		}
		if ($updateCpu === true || $updateRam === true || $updateHd === true || $updateTimezone === true || $updateHostname === true || $updatePassword === true || $updatePasswordReset === true)
			Vps::startVps($vzid);
		if ($updateCgroups === true) {
			$slices = $opts->keys['cgroups']->value;
			if (Vps::getVirtType() == 'kvm')
				Kvm::setupCgroups($vzid, $slices);
		}
	}
}
