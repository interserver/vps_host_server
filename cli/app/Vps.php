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
		passthru('/usr/bin/virsh dominfo '.$hostname.' >/dev/null 2>&1', $return);
		return $return == 0;
	}


	public function isRedhatBased() {
		return file_exists('/etc/redhat-release');
	}

	public function getRedhatVersion() {
		return floatval(trim(`cat /etc/redhat-release |sed s#"^[^0-9]* \([0-9\.]*\).*$"#"\\1"#g`));
	}

	public function getE2fsprogsVersion() {
		return floatval(trim(`e2fsck -V 2>&1 |head -n 1 | cut -d" " -f2 | cut -d"." -f1-2`));
	}


	public function getPoolType() {
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

	public function getTotalRam() {
		preg_match('/^MemTotal:\s+(\d+)\skB/', file_get_contents('/proc/meminfo'), $matches);
		$ram = floatval($matches[1]);
		return $ram;
	}

	public function getUsableRam() {
		$ram = floor(self::getTotalRam() / 100 * 70);
		return $ram;
	}

	public function getCpuCount() {
		preg_match('/CPU\(s\):\s+(\d+)/', `lscpu`, $matches);
		return intval($matches[1]);
	}

	public function getVpsMac($hostname) {
		$mac = XmlToArray::go(trim(`/usr/bin/virsh dumpxml {$hostname};`))['domain']['devices']['interface']['mac_attr']['address'];
		return $mac;
	}

	public function convertIdToMac($id, $useAll) {
		$prefix = $useAll == true ? '00:0C:29' : '00:16:3E';
		$suffix = strtoupper(sprintf("%06s", dechex($id)));
		$mac = $prefix.':'.substr($suffix, 0, 2).':'.substr($suffix, 2, 2).':'.substr($suffix, 4, 2);
		return $mac;
	}




}
