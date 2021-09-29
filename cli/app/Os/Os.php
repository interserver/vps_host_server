<?php
namespace App\Os;

use App\Vps;

class Os
{

	public static function isRedhatBased() {
		return file_exists('/etc/redhat-release');
	}

	public static function getRedhatVersion() {
		return floatval(trim(Vps::runCommand("cat /etc/redhat-release |sed s#'^[^0-9]* \([0-9\.]*\).*$'#'\\1'#g")));
	}

	public static function getE2fsprogsVersion() {
		return floatval(trim(Vps::runCommand("e2fsck -V 2>&1 |head -n 1 | cut -d' ' -f2 | cut -d'.' -f1-2")));
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
		preg_match('/CPU\(s\):\s+(\d+)/', Vps::runCommand("lscpu"), $matches);
		return intval($matches[1]);
	}

	public static function checkDeps() {
		Vps::getLogger()->info('Checking for dependancy failures and fixing them');
    	if (self::isRedhatBased() && self::getRedhatVersion() < 7) {
			if (self::getE2fsprogsVersion() <= 1.41) {
				if (!file_exists('/opt/e2fsprogs/sbin/e2fsck')) {
					echo Vps::runCommand("/admin/ports/install e2fsprogs");
				}
			}
    	}
	}
}
