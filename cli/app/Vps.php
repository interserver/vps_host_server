<?php
namespace App;

class Vps
{
	public static $virtBins = [
		'kvm' => '/usr/bin/virsh',
		'virtuozzo' => '/usr/bin/prlctl',
		'openvz' => '/usr/bin/vzctl',
		'lxc' => '/usr/bin/lxc',
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


}
