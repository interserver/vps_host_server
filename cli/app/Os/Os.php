<?php
namespace App\Os;

use App\Vps;

class Os
{

    /**
    * returns the systems main ip address
    * @return string the main ip address
    */
	public static function getIp() {
		$defaultRoute = trim(Vps::runCommand('ip route list | grep "^default" | sed s#"^default.*dev "#""#g | head -n 1 | cut -d" " -f1'));
		$ip = trim(Vps::runCommand("ifconfig {$defaultRoute} | grep inet | grep -v inet6 | awk '{ print $2 }' | cut -d: -f2"));
		return $ip;
	}

    /**
    * whether or not the system is a redhat based os
    * @return bool is a redhat based os
    */
	public static function isRedhatBased() {
		return file_exists('/etc/redhat-release');
	}

    /**
    * gets the redhat distro version
    * @return float redhat distro version
    */
	public static function getRedhatVersion() {
		return floatval(trim(Vps::runCommand("cat /etc/redhat-release |sed s#'^[^0-9]* \([0-9\.]*\).*$'#'\\1'#g")));
	}

    /**
    * gets the e2fsprogs version
    * @return float e2fsprogs version
    */
	public static function getE2fsprogsVersion() {
		return floatval(trim(Vps::runCommand("e2fsck -V 2>&1 |head -n 1 | cut -d' ' -f2 | cut -d'.' -f1-2")));
	}

    /**
    * gets the total system memory in kB
    * @return float total system memory in kb
    */
	public static function getTotalRam() {
		preg_match('/^MemTotal:\s+(\d+)\skB/', file_get_contents('/proc/meminfo'), $matches);
		$ram = floatval($matches[1]);
		return $ram;
	}

    /**
    * gets the usable memory in kb (70% of total memory)
    * @return float usable memory in kb
    */
	public static function getUsableRam() {
		$ram = floor(self::getTotalRam() / 100 * 70);
		return $ram;
	}

    /**
    * gets the numer of cpus/cores
    * @return int the number of cpus/cores
    *
    */
	public static function getCpuCount() {
		preg_match('/CPU\(s\):\s+(\d+)/', Vps::runCommand("lscpu"), $matches);
		return intval($matches[1]);
	}

	/**
	* checks the os dependancies making sure some things are installed
	*/
	public static function checkDeps() {
		Vps::getLogger()->info('Checking for dependancy failures and fixing them');
    	if (self::isRedhatBased() && self::getRedhatVersion() < 7) {
			if (self::getE2fsprogsVersion() <= 1.41) {
				if (!file_exists('/opt/e2fsprogs/sbin/e2fsck')) {
					Vps::getLogger()->write(Vps::runCommand("/admin/ports/install e2fsprogs"));
				}
			}
    	}
	}
}
