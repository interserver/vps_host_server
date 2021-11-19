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
		Vps::getLogger()->write(self::runCommand('pidof dhcpd >/dev/null', $return));
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
		Vps::getLogger()->write(Vps::runCommand("/bin/cp -f {$dhcpVps} {$dhcpVps}.backup;"));
    	Vps::getLogger()->write(Vps::runCommand("grep -v -e \"host {$vzid} \" -e \"fixed-address {$ip};\" {$dhcpVps}.backup > {$dhcpVps}"));
    	Vps::getLogger()->write(Vps::runCommand("echo \"host {$vzid} { hardware ethernet {$mac}; fixed-address {$ip}; }\" >> {$dhcpVps}"));
    	Vps::getLogger()->write(Vps::runCommand("rm -f {$dhcpVps}.backup;"));
		self::restart();
    }

    /**
    * regenerates the dhcpd.conf file
    * @param bool $useAll defaults to false, true for qs
    * @param bool $display defaults to false, true to display file contents instead of write them
    */
    public static function rebuildConf($useAll = false, $display = false) {
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
		if ($display === false)
			file_put_contents(self::getConfFile(), $file);
		else
			Vps::getLogger()->write('cat > '.self::getConfFile().' <<EOF'.PHP_EOL.$file.PHP_EOL.'EOF'.PHP_EOL);
    }

    /**
    * regenerates the dhcpd.vps hosts file
    * @param bool $useAll defaults to false, true for qs
    * @param bool $display defaults to false, true to display file contents instead of write them
    */
    public static function rebuildHosts($useAll = false, $display = false) {
    	$host = Vps::getHostInfo($useAll);
		$lines = [];
		foreach ($host['vps'] as $vps)
			$lines[] = 'host '.$vps['vzid'].' { hardware ethernet '.$vps['mac'].'; fixed-address '.$vps['ip'].';}';
		$file = implode(PHP_EOL, $lines);
		file_put_contents(self::getFile(), $file);
		if ($display === false)
			file_put_contents(self::getFile(), $file);
		else
			Vps::getLogger()->write('cat > '.self::getFile().' <<EOF'.PHP_EOL.$file.PHP_EOL.'EOF'.PHP_EOL);
    }

    /**
    * removes a host from dhcp
    * @param string $vzid
    */
    public static function remove($vzid) {
		$dhcpVps = self::getFile();
		Vps::getLogger()->write(Vps::runCommand("sed s#\"^host {$vzid} .*$\"#\"\"#g -i {$dhcpVps}"));
    	self::restart();
    }

    /**
    * restarts the service
    */
    public static function restart() {
		$dhcpService = self::getService();
		Vps::getLogger()->write(Vps::runCommand("systemctl restart {$dhcpService} 2>/dev/null || service {$dhcpService} restart 2>/dev/null || /etc/init.d/{$dhcpService} restart 2>/dev/null"));
    }
}
