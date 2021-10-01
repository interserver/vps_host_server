<?php
namespace App;

use App\XmlToArray;
use App\Os\Os;
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
		if (array_key_exists('verbose', self::$opts->keys)) {
			self::getLogger()->info("verbosity increased by ".self::$opts->keys['verbose']->value." levels");
			self::getLogger()->setLevel(self::getLogger()->getLevel() + self::$opts->keys['verbose']->value);
		}
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

    public static function getAllVps() {
		if (self::getVirtType() == 'kvm')
			return Kvm::getAllVps();
		elseif (self::getVirtType() == 'virtuozzo')
    		return Virtuozzo::getAllVps();
    }

    public static function getAllVpsAllVirts() {
		$virts = self::getInstalledVirts();
		$vpsList = [];
		if (in_array('virtuozzo', $virts))
			$vpsList = array_merge($vpsList, Virtuozzo::getAllVps());
		if (in_array('kvm', $virts))
			$vpsList = array_merge($vpsList, Kvm::getAllVps());
		return $vpsList;
    }

    public static function isVpsRunning($vzid) {
		return in_array($vzid, self::getRunningVps());
    }

	public static function vpsExists($vzid) {
		if (self::getVirtType() == 'kvm')
			return Kvm::vpsExists($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::vpsExists($vzid);
	}

	public static function getUrl($useAll = false) {
		return 'https://mynew.interserver.net/'.($useAll == true ? 'qs' : 'vps').'_queue.php';
	}

	public static function getPoolType() {
		$pool = '';
		if (self::getVirtType() == 'kvm')
			$pool = Kvm::getPoolType();
		else
			self::getLogger()->error("dont know how to handle virt type:".self::getVirtType());
		return $pool;
	}

	public static function getVpsMac($vzid) {
		return Kvm::getVpsMac($vzid);
	}

	public static function getVpsIps($vzid) {
		return Kvm::getVpsIps($vzid);
	}

	public static function convertIdToMac($id, $useAll) {
		$prefix = $useAll == true ? '00:0C:29' : '00:16:3E';
		$suffix = strtoupper(sprintf("%06s", dechex($id)));
		$mac = $prefix.':'.substr($suffix, 0, 2).':'.substr($suffix, 2, 2).':'.substr($suffix, 4, 2);
		return $mac;
	}

	public static function getVncPort($vzid) {
		if (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::getVncPort($vzid);
		else
			return Kvm::getVncPort($vzid);
	}

	public static function setupVnc($vzid, $clientIp = '') {
		if (self::getVirtType() == 'kvm')
			Kvm::setupVnc($vzid, $clientIp);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupVnc($vzid, $clientIp);
	}

	public static function vncScreenshot($vzid, $url) {
		$vncPort = self::getVncPort($vzid);
		$vncPort -= 5900;
		$base = self::$base;
		echo self::runCommand("{$base}/vps_kvm_screenshot.sh \"{$vncPort}\" \"{$url}?action=screenshot&name={$vzid}\";");
		sleep(2);
		echo self::runCommand("{$base}/vps_kvm_screenshot.sh \"{$vncPort}\" \"{$url}?action=screenshot&name={$vzid}\";");
		$vncPort += 5900;
	}

	public static function vncScreenshotSwift($vzid) {
		$vncPort = self::getVncPort($vzid);
		$base = Vps::$base;
		if ($vncPort != '' && intval($vncPort) > 1000) {
			$vncPort -= 5900;
			echo Vps::runCommand("{$base}/vps_kvm_screenshot_swift.sh {$vncPort} {$vzid}");
		}
	}

	public static function enableAutostart($vzid) {
		if (self::getVirtType() == 'kvm')
			Kvm::enableAutostart($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::enableAutostart($vzid);
	}

	public static function disableAutostart($vzid) {
		if (self::getVirtType() == 'kvm')
			Kvm::disableAutostart($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::disableAutostart($vzid);
	}

	public static function startVps($vzid) {
		self::getLogger()->info('Starting the VPS');
		if (self::getVirtType() == 'kvm')
			Kvm::startVps($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
        	Virtuozzo::startVps($vzid);
		if (!self::isVpsRunning($vzid))
			return 1;
	}

	public static function stopVps($vzid, $fast = false) {
		if (self::getVirtType() == 'kvm')
			Kvm::stopVps($vzid, $fast);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::stopVps($vzid);
	}

	public static function restartVps($vzid) {
		self::stopVps($vzid);
		self::startVps($vzid);
	}

	public static function deleteVps($vzid) {
		Vps::vncScreenshotSwift($vzid);
		Vps::stopVps($vzid);
		Vps::disableAutostart($vzid);
	}

	public static function destroyVps($vzid) {
		Vps::deleteVps($vzid);
		if (self::getVirtType() == 'kvm')
			Kvm::destroyVps($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::destroyVps($vzid);
	}

	public static function addIp($vzid, $ip) {
		if (self::getVirtType() == 'kvm')
			Kvm::addIp($vzid, $ip);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::addIp($vzid, $ip);
	}

	public static function removeIp($vzid, $ip) {
		if (self::getVirtType() == 'kvm')
			Kvm::removeIp($vzid, $ip);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::removeIp($vzid, $ip);
	}

	public static function changeIp($vzid, $ipOld, $ipNew) {
		if (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::changeIp($vzid, $ipOld, $ipNew);
		self::getLogger()->error('Changing an IP is not supported on this platform yet.');
		return false;
	}

	public static function setupRouting($vzid, $ip, $pool, $useAll, $id) {
		if (self::getVirtType() == 'kvm')
			Kvm::setupRouting($vzid, $ip, $pool, $useAll, $id);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupRouting($vzid, $id);
	}

	public static function blockSmtp($vzid, $id) {
		if (self::getVirtType() == 'kvm')
			Kvm::blockSmtp($vzid, $id);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::blockSmtp($vzid, $id);
	}

	public static function setupStorage($vzid, $device, $pool, $hd) {
		if (self::getVirtType() == 'kvm')
			Kvm::setupStorage($vzid, $device, $pool, $hd);
	}

	public static function defineVps($vzid, $hostname, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $hd, $maxRam, $maxCpu, $useAll, $password) {
		if (self::getVirtType() == 'kvm')
			return Kvm::defineVps($vzid, $hostname, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $maxRam, $maxCpu, $useAll);
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::defineVps($vzid, $hostname, $template, $ip, $extraIps, $ram, $cpu, $hd, $password);
		return true;
	}

	public static function setupCgroups($vzid, $useAll, $cpu) {
		$slices = $cpu;
		if ($useAll == false) {
			if (self::getVirtType() == 'kvm')
				Kvm::setupCgroups($vzid, $slices);
		}
	}

	public static function installTemplate($vzid, $template, $password, $device, $pool, $hd, $kpartxOpts) {
		if (self::getVirtType() == 'kvm')
			return Kvm::installTemplate($vzid, $template, $password, $device, $pool, $hd, $kpartxOpts);
		return true;
	}

	public static function setupWebuzo($vzid) {
		if (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupWebuzo($vzid);
	}

	public static function setupCpanel($vzid) {
		if (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupCpanel($vzid);
	}

	public static function runCommand($cmd, &$return = 0) {
		self::getLogger()->info2('cmd:'.$cmd);
		self::getLogger()->indent();
		$output = [];
		exec($cmd, $output, $return);
		self::getLogger()->debug('exit:'.$return);
		foreach ($output as $line)
			self::getLogger()->debug('out:'.$line);
		self::getLogger()->unIndent();
		$response = implode("\n", $output);
		return $response;
	}
}
