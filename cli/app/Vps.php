<?php
namespace App;

use App\XmlToArray;

class Vps
{
	public static $base = '/root/cpaneldirect';
	public static $virtBins = [
		'kvm' => '/usr/bin/virsh',
		'virtuozzo' => '/usr/bin/prlctl',
		'openvz' => '/usr/bin/vzctl',
		'lxc' => '/usr/bin/lxc',
	];
	public static $virtValidations = [
		'kvm-ok',
		'lscpu',
		'/proc/cpuinfo' => 'egrep "svm|vmx" /proc/cpuinfo',
		'virt-host-validate'
	];
	/** @var \CLIFramework\Logger */
	public static $logger;

    /**
    * @param \CLIFramework\Logger $logger
    */
	public static function setLogger($logger) {
		self::$logger = $logger;
	}

    public static function getInstalledVirts() {
		$found = [];
		foreach (self::$virtBins as $virt => $virtBin) {
			if (file_exists($virtBin)) {
				$found[] = $virt;
			}
		}
		return $found;
    }

    public static function isVirtualHost() {
		$virts = self::getInstalledVirts();
		return count($virts) > 0;
    }

    public static function getRunningVps() {
		return explode("\n", trim(`virsh list --name`));
    }

    public static function isVpsRunning($hostname) {
		return in_array($hostname, self::getRunningVps());
    }

	public static function vpsExists($hostname) {
		$hostname = escapeshellarg($hostname);
		passthru('/usr/bin/virsh dominfo '.$hostname.' >/dev/null 2>&1', $return);
		return $return == 0;
	}

	public static function isRedhatBased() {
		return file_exists('/etc/redhat-release');
	}

	public static function getRedhatVersion() {
		return floatval(trim(`cat /etc/redhat-release |sed s#"^[^0-9]* \([0-9\.]*\).*$"#"\\1"#g`));
	}

	public static function getE2fsprogsVersion() {
		return floatval(trim(`e2fsck -V 2>&1 |head -n 1 | cut -d" " -f2 | cut -d"." -f1-2`));
	}

	public static function getPoolType() {
		$pool = XmlToArray::go(trim(`virsh pool-dumpxml vz 2>/dev/null`))['pool_attr']['type'];
		if ($pool == '') {
			echo `{self::$base}/create_libvirt_storage_pools.sh`;
			$pool = XmlToArray::go(trim(`virsh pool-dumpxml vz 2>/dev/null`))['pool_attr']['type'];
		}
		if (preg_match('/vz/', `virsh pool-list --inactive`)) {
			echo `virsh pool-start vz;`;
		}
		return $pool;
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
		preg_match('/CPU\(s\):\s+(\d+)/', `lscpu`, $matches);
		return intval($matches[1]);
	}

	public static function getVpsMac($hostname) {
		$hostname = escapeshellarg($hostname);
		$mac = XmlToArray::go(trim(`/usr/bin/virsh dumpxml {$hostname};`))['domain']['devices']['interface']['mac_attr']['address'];
		return $mac;
	}

	public static function convertIdToMac($id, $useAll) {
		$prefix = $useAll == true ? '00:0C:29' : '00:16:3E';
		$suffix = strtoupper(sprintf("%06s", dechex($id)));
		$mac = $prefix.':'.substr($suffix, 0, 2).':'.substr($suffix, 2, 2).':'.substr($suffix, 4, 2);
		return $mac;
	}

	public static function runBuildEbtables() {
		if (self::getPoolType() != 'zfs') {
			echo `bash {self::$base}/run_buildebtables.sh`;
		}
	}

	public static function lockXinetd() {
		touch('/tmp/_securexinetd');
	}

	public static function unlockXinetd() {
		echo `rm -f /tmp/_securexinetd;`;
	}

	public static function removeXinetd($hostname) {
		$hostname = escapeshellarg($hostname);
		echo `rm -f /etc/xinetd.d/{$hostname}`;
	}

	public static function restartXinetd() {
		echo `service xinetd restart 2>/dev/null || /etc/init.d/xinetd restart 2>/dev/null`;
	}

	public static function getVncPort($hostname) {
		$vncPort = trim(`virsh vncdisplay {$hostname} | cut -d: -f2 | head -n 1`);
		if ($vncPort == '') {
			sleep(2);
			$vncPort = trim(`virsh vncdisplay {$hostname} | cut -d: -f2 | head -n 1`);
			if ($vncPort == '') {
				sleep(2);
				$vncPort = trim(`virsh dumpxml {$hostname} |grep -i "graphics type='vnc'" | cut -d\' -f4`);
			} else {
				$vncPort += 5900;
			}
		} else {
			$vncPort += 5900;
		}
		return $vncPort;
	}

	public static function enableAutostart($hostname) {
		echo `/usr/bin/virsh autostart {$hostname}`;
	}

	public static function disableAutostart($hostname) {
		echo `/usr/bin/virsh autostart --disable {$hostname}`;
	}

	public static function startVps($hostname) {
		self::$logger->info('Starting the VPS');
		self::removeXinetd($hostname);
		self::restartXinetd();
		echo `/usr/bin/virsh start {$hostname}`;
		self::runBuildEbtables();
		if (!self::isVpsRunning($hostname))
			return 1;
	}

	public static function stopVps($hostname) {
		self::$logger->info('Stopping the VPS');
		self::$logger->indent();
		self::$logger->info('Sending Softwawre Power-Off');
		echo `/usr/bin/virsh shutdown {$hostname}`;
		$stopped = false;
		$waited = 0;
		$maxWait = 120;
		$sleepTime = 10;
		while ($waited <= $maxWait && $stopped == false) {
			if (self::isVpsRunning($hostname)) {
				self::$logger->info('still running, waiting (waited '.$waited.'/'.$maxWait.' seconds)');
				sleep($sleepTime);
				$waited += $sleepTime;
			} else {
				self::$logger->info('appears to have cleanly shutdown');
				$stopped = true;
			}
		}
		if ($stopped === false) {
			self::$logger->info('Sending Hardware Power-Off');
			echo `/usr/bin/virsh destroy {$hostname};`;
		}
		self::removeXinetd($hostname);
		self::restartXinetd();
		self::$logger->unIndent();
	}

	public static function restartVps($hostname) {
		self::stopVps($hostname);
		self::startVps($hostname);
	}
}
