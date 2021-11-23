<?php
namespace App;

use App\XmlToArray;
use App\Os\Os;
use App\Os\Xinetd;
use App\Vps\Kvm;
use App\Vps\Lxc;
use App\Vps\Virtuozzo;
use App\Vps\OpenVz;

/**
* Provides OOP interface to virtualization technologies
*/
class Vps
{
	public static $base = '/root/cpaneldirect';
	public static $virtBins = [
		'virtuozzo' => '/usr/bin/prlctl',
		'openvz' => '/usr/sbin/vzctl',
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
	/** @var \App\Logger */
	protected static $logger;
	/** @var array */
	protected static $args;
	/** @var \GetOptionKit\OptionCollection */
	protected static $opts;

	/**
	* @param \App\Logger $logger
	*/
	public static function setLogger($logger) {
		self::$logger = $logger;
	}

	/**
	* @return \App\Logger
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
		self::setVirtType(array_key_exists('virt', self::$opts->keys) && self::$opts->keys['virt']->value != 'auto' ? self::$opts->keys['virt']->value : false);
		if (array_key_exists('verbose', self::$opts->keys)) {
			self::getLogger()->info("verbosity increased by ".self::$opts->keys['verbose']->value." levels");
			self::getLogger()->setLevel(self::getLogger()->getLevel() + self::$opts->keys['verbose']->value);
		}
	}

    /**
    * returns an array of installed virtualization types
    *
    * @return array
    */
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

    /**
    * determins if the host is setup for virtualization or not
    *
    * @return bool
    */
	public static function isVirtualHost() {
		$virt = self::getVirtType();
		if ($virt !== false)
			self::getLogger()->info2('using '.$virt.' virtualization.');
		return $virt !== false;
	}

    /**
    * gets the type of virtualization we'll be using
    *
    * @return string
    */
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

	/**
	* returns an array containing information about the host server, vlans, and vps's
	*
	* @param bool $useAll true for quickservers
	* @return array the host info
	*/
	public static function getHostInfo($useAll = false) {
		$response = trim(self::runCommand('curl -s '.escapeshellarg(self::getUrl($useAll).'?action=get_info')));
		$host = json_decode($response, true);
		if (!is_array($host) || !isset($host['vlans'])) {
			self::getLogger()->error("invalid response getting host info:".$response);
			return false;
		}
		/* $vps = {
			"id": "2324459",
			"hostname": "vps2324459",
			"vzid": "vps2324459",
			"mac": "00:16:3e:23:77:eb",
			"ip": "208.73.202.209",
			"status": "active",
			"server_status": "running",
			"vnc": "79.156.208.231"
		} */

        @mkdir($_SERVER['HOME'].'/.provirted', 0750, true);
        file_put_contents($_SERVER['HOME'].'/.provirted/host.json', $response);
        return $host;
	}

    /**
    * return a list of the running vps
    *
    * @return array
    */
	public static function getRunningVps() {
		if (self::getVirtType() == 'kvm')
			return Kvm::getRunningVps();
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::getRunningVps();
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::getRunningVps();
	}

    /**
    * return a list of all the vps
    *
    * @return array
    */
	public static function getAllVps() {
		if (self::getVirtType() == 'kvm')
			return Kvm::getAllVps();
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::getAllVps();
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::getAllVps();
	}

    /**
    * return a list of all the vps on all installed virtualization types
    *
    * @return array
    */
	public static function getAllVpsAllVirts() {
		$virts = self::getInstalledVirts();
		$vpsList = [];
		if (in_array('virtuozzo', $virts))
			$vpsList = array_merge($vpsList, Virtuozzo::getAllVps());
		if (in_array('openvz', $virts))
			$vpsList = array_merge($vpsList, OpenVz::getAllVps());
		if (in_array('kvm', $virts))
			$vpsList = array_merge($vpsList, Kvm::getAllVps());
		return $vpsList;
	}

    /**
    * determins if a vps is running or not
    *
    * @param int|string $vzid
    * @return bool
    */
	public static function isVpsRunning($vzid) {
		return in_array($vzid, self::getRunningVps());
	}

    /**
    * determines if a vps exists or not
    *
    * @param string $vzid
    * @return bool
    */
	public static function vpsExists($vzid) {
		if (self::getVirtType() == 'kvm')
			return Kvm::vpsExists($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::vpsExists($vzid);
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::vpsExists($vzid);
	}

	public static function getUrl($useAll = false) {
		return 'https://mynew.interserver.net/'.($useAll == true ? 'qs' : 'vps').'_queue.php';
	}

    /**
    * gets the type of storage pool
    *
    * @return string
    */
	public static function getPoolType() {
		$pool = '';
		if (self::getVirtType() == 'kvm')
			$pool = Kvm::getPoolType();
		else
			self::getLogger()->error("dont know how to handle virt type:".self::getVirtType());
		return $pool;
	}

    /**
    * gets the mac address of a vps
    *
    * @param int|string $vzid
    * @return string
    */
	public static function getVpsMac($vzid) {
		return Kvm::getVpsMac($vzid);
	}

    /**
    * gets the ips configured on a vps
    *
    * @param int|string $vzid
    * @return array
    */
	public static function getVpsIps($vzid) {
		if (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::getVpsIps($vzid);
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::getVpsIps($vzid);
		elseif (self::getVirtType() == 'kvm')
			return Kvm::getVpsIps($vzid);
	}

    /**
    * converts an order id into a mac address
    *
    * @param int $id
    * @param bool $useAll
    * @return string
    */
	public static function convertIdToMac($id, $useAll) {
		$prefix = $useAll == true ? '00:0C:29' : '00:16:3E';
		$suffix = strtoupper(sprintf("%06s", dechex($id)));
		$mac = $prefix.':'.substr($suffix, 0, 2).':'.substr($suffix, 2, 2).':'.substr($suffix, 4, 2);
		return $mac;
	}

    /**
    * gets the vnc/spice ports for a vps
    *
    * @param int|string $vzid
    * @return array
    */
	public static function getVpsRemotes($vzid) {
		if (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::getVpsRemotes($vzid);
		else
			return Kvm::getVpsRemotes($vzid);
	}

    /**
    * gets the vnc port for a vps
    *
    * @param int|string $vzid
    * @return int|string
    */
	public static function getVncPort($vzid) {
		if (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::getVncPort($vzid);
		else
			return Kvm::getVncPort($vzid);
	}

	public static function setupVnc($vzid, $clientIp = '') {
		Xinetd::lock();
        $remotes = self::getVpsRemotes($vzid);
        if (self::getVirtType() == 'virtuozzo') {
        	$vps = Virtuozzo::getVps($vzid);
        	$vzid = $vps['EnvID'];
		}
        self::getLogger()->write('Parsing Services...');
		$services = Xinetd::parseEntries();
		self::getLogger()->write('done'.PHP_EOL);
		foreach ($services as $serviceName => $serviceData) {
			if (in_array($serviceName, [$vzid, $vzid.'-spice'])
				|| (isset($serviceData['port']) && in_array(intval($serviceData['port']), array_values($remotes)))) {
				self::getLogger()->write("removing {$serviceData['filename']}\n");
				unlink($serviceData['filename']);
			}
		}
		foreach ($remotes as $type => $port) {
			self::getLogger()->write("setting up {$type} on {$vzid} port {$port}".(trim($clientIp) != '' ? " ip {$clientIp}" : "")."\n");
			Xinetd::setup($type == 'vnc' ? $vzid : $vzid.'-'.$type, $port, trim($clientIp) != '' ? $clientIp : false);
		}
		Xinetd::unlock();
		Xinetd::restart();
	}

	public static function vncScreenshot($vzid, $url) {
		if (in_array(self::getVirtType(), ['kvm', 'virtuozzo'])) {
			$vncPort = self::getVncPort($vzid);
			$vncPort -= 5900;
			$base = self::$base;
			self::getLogger()->write(self::runCommand("{$base}/vps_kvm_screenshot.sh \"{$vncPort}\" \"{$url}?action=screenshot&name={$vzid}\";"));
			sleep(2);
			self::getLogger()->write(self::runCommand("{$base}/vps_kvm_screenshot.sh \"{$vncPort}\" \"{$url}?action=screenshot&name={$vzid}\";"));
			$vncPort += 5900;
		}
	}

	public static function vncScreenshotSwift($vzid) {
		if (in_array(self::getVirtType(), ['kvm', 'virtuozzo'])) {
			$vncPort = self::getVncPort($vzid);
			$base = self::$base;
			if ($vncPort != '' && intval($vncPort) > 1000) {
				$vncPort -= 5900;
				self::getLogger()->write(self::runCommand("{$base}/vps_kvm_screenshot_swift.sh {$vncPort} {$vzid}"));
			}
		}
	}

	public static function enableAutostart($vzid) {
		if (self::getVirtType() == 'kvm')
			Kvm::enableAutostart($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::enableAutostart($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::enableAutostart($vzid);
	}

	public static function disableAutostart($vzid) {
		if (self::getVirtType() == 'kvm')
			Kvm::disableAutostart($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::disableAutostart($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::disableAutostart($vzid);
	}

	public static function startVps($vzid) {
		self::getLogger()->info('Starting the VPS');
		if (self::getVirtType() == 'kvm')
			Kvm::startVps($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::startVps($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::startVps($vzid);
		if (!self::isVpsRunning($vzid))
			return 1;
	}

	public static function stopVps($vzid, $fast = false) {
		if (self::getVirtType() == 'kvm')
			Kvm::stopVps($vzid, $fast);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::stopVps($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::stopVps($vzid);
	}

	public static function restartVps($vzid) {
		self::stopVps($vzid);
		self::startVps($vzid);
	}

	public static function deleteVps($vzid) {
		self::vncScreenshotSwift($vzid);
		self::stopVps($vzid);
		self::disableAutostart($vzid);
	}

	public static function destroyVps($vzid) {
		//self::deleteVps($vzid);
		if (self::getVirtType() == 'kvm')
			Kvm::destroyVps($vzid);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::destroyVps($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::destroyVps($vzid);
	}

	public static function addIp($vzid, $ip) {
		if (self::getVirtType() == 'kvm')
			Kvm::addIp($vzid, $ip);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::addIp($vzid, $ip);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::addIp($vzid, $ip);
	}

	public static function removeIp($vzid, $ip) {
		if (self::getVirtType() == 'kvm')
			Kvm::removeIp($vzid, $ip);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::removeIp($vzid, $ip);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::removeIp($vzid, $ip);
	}

	public static function changeIp($vzid, $ipOld, $ipNew) {
		if (self::getVirtType() == 'virtuozzo')
			return Virtuozzo::changeIp($vzid, $ipOld, $ipNew);
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::changeIp($vzid, $ipOld, $ipNew);
		self::getLogger()->error('Changing an IP is not supported on this platform yet.');
		return false;
	}

	public static function setupRouting($vzid, $ip, $pool, $useAll, $id) {
		if (self::getVirtType() == 'kvm')
			Kvm::setupRouting($vzid, $ip, $pool, $useAll, $id);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupRouting($vzid, $id);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::setupRouting($vzid, $id);
	}

	public static function blockSmtp($vzid, $id) {
		if (self::getVirtType() == 'kvm')
			Kvm::blockSmtp($vzid, $id);
		elseif (self::getVirtType() == 'virtuozzo')
			Virtuozzo::blockSmtp($vzid, $id);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::blockSmtp($vzid, $id);
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
		elseif (self::getVirtType() == 'openvz')
			return OpenVz::defineVps($vzid, $hostname, $template, $ip, $extraIps, $ram, $cpu, $hd, $password);
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
		elseif (self::getVirtType() == 'openvz')
			OpenVz::setupWebuzo($vzid);
	}

	public static function setupCpanel($vzid) {
		if (self::getVirtType() == 'virtuozzo')
			Virtuozzo::setupCpanel($vzid);
		elseif (self::getVirtType() == 'openvz')
			OpenVz::setupCpanel($vzid);
	}

	public static function getHistoryChoices() {
		$return = self::getLogger()->getHistory();
		array_unshift($return, 'last');
	}

	public static function runCommand($cmd, &$return = 0) {
		$descs = [
			0 => ['pipe','r'],
			1 => ['pipe','w'],
			2 => ['pipe','w']
		];
		$stdout = '';
		$stderr = '';
		$proc = proc_open($cmd, $descs, $pipes);
		if (is_resource($proc)) {
			while (!feof($pipes[1])) {
				$stdout .= fgets($pipes[1]);
			}
			while (!feof($pipes[2])) {
				$stderr .= fgets($pipes[2]);
			}
			fclose($pipes[0]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$status = proc_get_status($proc);
			$retVal = proc_close($proc);
			$return = $status['running'] ? $retVal : $status['exitcode'];
		} else {
			$stderr = 'couldnt run';
			$return = 1;
		}
		self::getLogger()->info2('cmd:'.$cmd);
		self::getLogger()->debug('out:'.$stdout);
		$history = [
			'type' => 'command',
			'command' => $cmd,
			'output' => $stdout,
			'return' => $return
		];
		if ($stderr != '') {
			$history['error'] = $stderr;
			self::getLogger()->debug('error:'.$stderr);
		}
		/*
		$output = [];
		exec($cmd, $output, $return);
		self::getLogger()->indent();
		foreach ($output as $line)
			self::getLogger()->debug('out:'.$line);
		self::getLogger()->unIndent();
		self::getLogger()->debug('exit:'.$return);
		$response = implode("\n", $output);
		*/
		self::getLogger()->addHistory($history);
		return $stdout.$stderr;
	}
}
