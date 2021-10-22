<?php
namespace App\Os;

use App\Vps;

/**
* DHCPD Service Management Class
*/
class Dhcpd
{
    /**
    * is the service running
    * @return bool
    */
	public static function isRunning() {
		echo self::runCommand('pidof dhcpd >/dev/null', $return);
		return $return == 0;
	}

    /**
    * gets an array of hosts and thier ip+mac assignments
    * @return array
    */
	public static function getHosts() {
		$dhcpFile = self::getFile();
		$dhcpData = file_get_contents($dhcpFile);
		$hosts = [];
		if (preg_match_all('/^\s*host\s+(?P<host>\S+)\s+{\s+hardware\s+ethernet\s+(?P<mac>\S+)\s*;\s*fixed-address\s+(?P<ip>\S+)\s*;\s*}/msuU', $dhcpData, $matches)) {
			foreach ($matches[0] as $idx => $match) {
				$host = $matches['host'][$idx];
				$mac = $matches['mac'][$idx];
				$ip = $matches['ip'][$idx];
				$hosts[$host] = ['ip' => $ip, 'mac' => $mac];
			}
		}
		return $hosts;
	}

    /**
    * returns the name of the dhcpd config file
    * @return string
    */
	public static function getConfFile() {
		return file_exists('/etc/dhcp/dhcpd.conf') ? '/etc/dhcp/dhcpd.conf' : '/etc/dhcpd.conf';
	}

    /**
    * returns the name of the dhcpd hosts file
    * @return string
    */
	public static function getFile() {
		return file_exists('/etc/dhcp/dhcpd.vps') ? '/etc/dhcp/dhcpd.vps' : '/etc/dhcpd.vps';
	}

    /**
    * returns the name of the dhcp service
    * @return string
    */
	public static function getService() {
		return file_exists('/etc/apt') ? 'isc-dhcp-server' : 'dhcpd';
	}

    /**
    * sets up a new host for dhcp
    * @param string $vzid hostname
    * @param string $ip ip address
    * @param string $mac mac address
    */
    public static function setup($vzid, $ip, $mac) {
		Vps::getLogger()->info('Setting up DHCPD');
		$mac = Vps::getVpsMac($vzid);
		$dhcpVps = self::getFile();
		echo Vps::runCommand("/bin/cp -f {$dhcpVps} {$dhcpVps}.backup;");
    	echo Vps::runCommand("grep -v -e \"host {$vzid} \" -e \"fixed-address {$ip};\" {$dhcpVps}.backup > {$dhcpVps}");
    	echo Vps::runCommand("echo \"host {$vzid} { hardware ethernet {$mac}; fixed-address {$ip}; }\" >> {$dhcpVps}");
    	echo Vps::runCommand("rm -f {$dhcpVps}.backup;");
		self::restart();
    }

    /**
    * regenerates the dhcpd.conf file
    * @param bool $useAll defaults to false, optional true for qs
    */
    public static function rebuildConf($useAll = false) {
    	$host = Vps::getHostInfo($useAll);
		$file = 'authoritative;
option domain-name "interserver.net";
option domain-name-servers 1.1.1.1;
allow bootp;
allow booting;
ddns-update-style interim;
default-lease-time 600;
max-lease-time 7200;
log-facility local7;
include "'.self::getFile().'";

shared-network myvpn {
';
		foreach ($host['vlans'] as $vlanId => $vlanData)
			$file .= 'subnet '.$vlanData['network_ip'].' netmask '.$vlanData['netmask'].' {
	next-server '.$vlanData['hostmin'].';
	#range dynamic-bootp '.long2ip(ip2long($vlanData['hostmin']) + 1).' '.$vlanData['hostmax'].';
	option domain-name-servers 64.20.34.50;
	option domain-name "interserver.net";
	option routers '.long2ip(ip2long($vlanData['hostmin'])).';
	option broadcast-address '.$vlanData['broadcast'].';
	default-lease-time 86400; # 24 hours
	max-lease-time 172800; # 48 hours
}
';
		$file .= '}';
		file_put_contents(self::getConfFile(), $file);
    }

    /**
    * regenerates the dhcpd.vps hosts file
    * @param bool $useAll defaults to false, optional true for qs
    */
    public static function rebuildHosts($useAll = false) {
    	$host = Vps::getHostInfo($useAll);
		$file = '';
		foreach ($host['vps'] as $vps)
			$file .= 'host '.$vps['vzid'].' { hardware ethernet '.$vps['mac'].'; fixed-address '.$vps['ip'].';}'.PHP_EOL;
		file_put_contents(self::getFile(), $file);
    }

    /**
    * removes a host from dhcp
    * @param string $vzid
    */
    public static function remove($vzid) {
		$dhcpVps = self::getFile();
		echo Vps::runCommand("sed s#\"^host {$vzid} .*$\"#\"\"#g -i {$dhcpVps}");
    	self::restart();
    }

    /**
    * restarts the service
    */
    public static function restart() {
		$dhcpService = self::getService();
		echo Vps::runCommand("systemctl restart {$dhcpService} 2>/dev/null || service {$dhcpService} restart 2>/dev/null || /etc/init.d/{$dhcpService} restart 2>/dev/null");
    }
}
