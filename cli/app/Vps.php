<?php
namespace App;

use App\XmlToArray;
use App\Vps\Kvm;
use App\Vps\Lxc;
use App\Vps\Virtuozzo;
use App\Vps\OpenVz;

class Vps
{
	public static $base = '/root/cpaneldirect';
	public static $virtBins = [
		'virtuozzo' => '/usr/bin/prlctl',
		'openvz' => '/usr/bin/vzctl',
		'kvm' => '/usr/bin/virsh',
		'lxc' => '/usr/bin/lxc',
	];
	public static $virtInstalled = false;
	public static $virtType = false;
	public static $virtValidations = [
		'kvm-ok',
		'lscpu',
		'/proc/cpuinfo' => 'egrep "svm|vmx" /proc/cpuinfo',
		'virt-host-validate'
	];
	/** @var \CLIFramework\Logger */
	protected static $logger;
	/** @var array */
	protected static $args;
	/** @var \GetOptionKit\OptionCollection */
	protected static $opts;

    /**
    * @param \CLIFramework\Logger $logger
    */
	public static function setLogger($logger) {
		self::$logger = $logger;
	}

	/**
    * @return \CLIFramework\Logger
	*/
	public static function getLogger() {
		return self::$logger;
	}

	/**
	* @param \GetOptionKit\OptionCollection $opts
	* @param array $args
	*/
	public static function init($opts, array $args) {
		self::$opts = $opts;
		self::$args = $args;
		self::setVirtType(array_key_exists('virt', self::$opts->keys) ? self::$opts->keys['virt']->value : false);
	}

    public static function getInstalledVirts() {
    	if (self::$virtInstalled === false) {
    		self::getLogger()->info2('detecting installed virtualization types.');
    		self::getLogger()->indent();
			$found = [];
			foreach (self::$virtBins as $virt => $virtBin) {
				if (file_exists($virtBin)) {
					self::getLogger()->info2('found '.$virt.' virtualization installed');
					$found[] = $virt;
				}
			}
    		self::getLogger()->unIndent();
			self::$virtInstalled = $found;
		}
		return self::$virtInstalled;
    }

    public static function isVirtualHost() {
		$virt = self::getVirtType();
		if ($virt !== false)
			self::getLogger()->info2('using '.$virt.' virtualization.');
		return $virt !== false;
    }

    public static function getVirtType() {
		$virts = self::getInstalledVirts();
		foreach ($virts as $idx => $virt)
			if (self::$virtType == false || self::$virtType == $virt)
				return self::$virtType = $virt;
		return false;
    }

    public static function setVirtType($virt) {
    	if ($virt !== false)
    		self::getLogger()->info2('trying to force '.$virt.' virtualization.');
		self::$virtType = $virt;
    }

    public static function getRunningVps() {
		if (self::getVirtType() == 'kvm')
			return Kvm::getRunningVps();
		elseif (self::getVirtType() == 'virtuozzo')
    		return Virtuozzo::getRunningVps();
    }

    public static function isVpsRunning($hostname) {
		return in_array($hostname, self::getRunningVps());
    }

	public static function vpsExists($hostname) {
		if (self::getVirtType() == 'kvm')
			return Kvm::vpsExists($hostname);
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::vpsExists($hostname);
	}

	public static function getUrl($useAll = false) {
		return 'https://mynew.interserver.net/'.($useAll == true ? 'qs' : 'vps').'_queue.php';
	}

	public static function isRedhatBased() {
		return file_exists('/etc/redhat-release');
	}

	public static function getRedhatVersion() {
		return floatval(trim(self::runCommand("cat /etc/redhat-release |sed s#'^[^0-9]* \([0-9\.]*\).*$'#'\\1'#g")));
	}

	public static function getE2fsprogsVersion() {
		return floatval(trim(self::runCommand("e2fsck -V 2>&1 |head -n 1 | cut -d' ' -f2 | cut -d'.' -f1-2")));
	}

	public static function getTotalRam() {
		preg_match('/^MemTotal:\s+(\d+)\skB/', file_get_contents('/proc/meminfo'), $matches);
		$ram = floatval($matches[1]);
		return $ram;
	}

	public static function getUsableRam() {
		$ram = floor(self::getTotalRam() / 100 * 70);
		return $ram;
	}

	public static function getCpuCount() {
		preg_match('/CPU\(s\):\s+(\d+)/', self::runCommand("lscpu"), $matches);
		return intval($matches[1]);
	}

	public static function getPoolType() {
		$pool = '';
		if (self::getVirtType() == 'kvm')
			$pool = Kvm::getPoolType();
		else
			self::getLogger()->error("dont know how to handle virt type:".self::getVirtType());
		return $pool;
	}

	public static function getVpsMac($hostname) {
		return Kvm::getVpsMac($hostname);
	}

	public static function getVpsIps($hostname) {
		return Kvm::getVpsIps($hostname);
	}

	public static function convertIdToMac($id, $useAll) {
		$prefix = $useAll == true ? '00:0C:29' : '00:16:3E';
		$suffix = strtoupper(sprintf("%06s", dechex($id)));
		$mac = $prefix.':'.substr($suffix, 0, 2).':'.substr($suffix, 2, 2).':'.substr($suffix, 4, 2);
		return $mac;
	}

	public static function checkDeps() {
		self::getLogger()->info('Checking for dependancy failures and fixing them');
    	if (self::isRedhatBased() && self::getRedhatVersion() < 7) {
			if (self::getE2fsprogsVersion() <= 1.41) {
				if (!file_exists('/opt/e2fsprogs/sbin/e2fsck')) {
					echo self::runCommand("/admin/ports/install e2fsprogs");
				}
			}
    	}
	}

	public static function lockXinetd() {
		touch('/tmp/_securexinetd');
	}

	public static function unlockXinetd() {
		echo self::runCommand("rm -f /tmp/_securexinetd;");
	}

	public static function removeXinetd($hostname) {
		$hostname = escapeshellarg($hostname);
		echo self::runCommand("rm -f /etc/xinetd.d/{$hostname}");
	}

	public static function restartXinetd() {
		echo self::runCommand("service xinetd restart 2>/dev/null || /etc/init.d/xinetd restart 2>/dev/null");
	}

	public static function isXinetdRunning() {
		echo self::runCommand('pidof xinetd >/dev/null', $return);
		return $return == 0;
	}

	public static function getVncPort($hostname) {
		if (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::getVncPort($hostname);
		else
			return Kvm::getVncPort($hostname);
	}

	public static function setupVnc($hostname, $clientIp = '') {
		if (self::getVirtType() == 'kvm')
			Kvm::setupVnc($hostname, $clientIp);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupVnc($hostname, $clientIp);
	}

	public static function vncScreenshot($hostname, $url) {
		$vncPort = self::getVncPort($hostname);
		$vncPort -= 5900;
		echo self::runCommand("{self::$base}/vps_kvm_screenshot.sh \"{$vncPort}\" \"{$url}?action=screenshot&name={$hostname}\";");
		sleep(2);
		echo self::runCommand("{self::$base}/vps_kvm_screenshot.sh \"{$vncPort}\" \"{$url}?action=screenshot&name={$hostname}\";");
		$vncPort += 5900;
	}

	public static function vncScreenshotSwift($hostname) {
		$vncPort = self::getVncPort($hostname);
		if ($vncPort != '' && intval($vncPort) > 1000) {
			$vncPort -= 5900;
			echo Vps::runCommand("/root/cpaneldirect/vps_kvm_screenshot_swift.sh {$vncPort} {$hostname}");
		}
	}

	public static function enableAutostart($hostname) {
		if (self::getVirtType() == 'kvm')
			Kvm::enableAutostart($hostname);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::enableAutostart($hostname);
	}

	public static function disableAutostart($hostname) {
		if (self::getVirtType() == 'kvm')
			Kvm::disableAutostart($hostname);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::disableAutostart($hostname);
	}

	public static function startVps($hostname) {
		self::getLogger()->info('Starting the VPS');
		if (self::getVirtType() == 'kvm')
			Kvm::startVps($hostname);
		elseif (self::getVirtType() == 'virtuozzo')
        	Virtuozzo::startVps($hostname);
		if (!self::isVpsRunning($hostname))
			return 1;
	}

	public static function stopVps($hostname, $fast = false) {
		if (self::getVirtType() == 'kvm')
			Kvm::stopVps($hostname, $fast);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::stopVps($hostname);
	}

	public static function restartVps($hostname) {
		self::stopVps($hostname);
		self::startVps($hostname);
	}

	public static function deleteVps($hostname) {
		Vps::vncScreenshotSwift($hostname);
		Vps::stopVps($hostname);
		Vps::disableAutostart($hostname);
	}

	public static function destroyVps($hostname) {
		Vps::deleteVps($hostname);
		if (self::getVirtType() == 'kvm')
			Kvm::destroyVps($hostname);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::destroyVps($hostname);
	}

	public static function addIp($hostname, $ip) {
		if (self::getVirtType() == 'kvm')
			Kvm::addIp($hostname, $ip);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::addIp($hostname, $ip);
	}

	public static function setupRouting($hostname, $ip, $pool, $useAll, $id) {
		if (self::getVirtType() == 'kvm')
			Kvm::setupRouting($hostname, $ip, $pool, $useAll, $id);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupRouting($hostname, $id);
	}

	public static function blockSmtp($hostname, $id) {
		if (self::getVirtType() == 'kvm')
			Kvm::blockSmtp($hostname, $id);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::blockSmtp($hostname, $id);
	}

	public static function setupStorage($hostname, $device, $pool, $hd) {
		if (self::getVirtType() == 'kvm')
			Kvm::setupStorage($hostname, $device, $pool, $hd);
	}

	public static function defineVps($hostname, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $maxRam, $maxCpu, $useAll, $password) {
		if (self::getVirtType() == 'kvm')
			return Kvm::defineVps($hostname, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $maxRam, $maxCpu, $useAll);
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::defineVps($hostname, $template, $ip, $extraIps, $ram, $cpu, $password);
		return true;
	}

	public static function setupCgroups($hostname, $useAll, $cpu) {
		$slices = $cpu;
		if ($useAll == false) {
			if (self::getVirtType() == 'kvm')
				Kvm::setupCgroups($hostname, $slices);
		}
	}

	public static function installTemplate($hostname, $template, $password, $device, $pool, $hd, $kpartxOpts) {
		if (self::getVirtType() == 'kvm')
			return Kvm::installTemplate($hostname, $template, $password, $device, $pool, $hd, $kpartxOpts);
		return true;
	}

	public static function setupWebuzo($hostname) {
		if (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupWebuzo($hostname);
	}

	public static function setupCpanel($hostname) {
		if (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupCpanel($hostname);
	}

	public static function runCommand($cmd, &$return = 0) {
		self::getLogger()->indent();
		self::getLogger()->info2('runnning:'.$cmd);
		self::getLogger()->indent();
		$output = [];
		exec($cmd, $output, $return);
		self::getLogger()->debug('exit code:'.$return);
		foreach ($output as $line)
			self::getLogger()->debug('output:'.$line);
		self::getLogger()->unIndent();
		$output = implode(PHP_EOL, $output);
		self::getLogger()->unIndent();
		return $output;
	}
}
