<?php
namespace App\Os;

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
    * @param string $hostname hostname
    * @param string $ip ip address
    * @param string $mac mac address
    */
    public static function setup($hostname, $ip, $mac) {
		Vps::getLogger()->info('Setting up DHCPD');
		$mac = Vps::getVpsMac($hostname);
		$dhcpVps = self::getFile();
		echo Vps::runCommand("/bin/cp -f {$dhcpVps} {$dhcpVps}.backup;");
    	echo Vps::runCommand("grep -v -e \"host {$hostname} \" -e \"fixed-address {$ip};\" {$dhcpVps}.backup > {$dhcpVps}");
    	echo Vps::runCommand("echo \"host {$hostname} { hardware ethernet {$mac}; fixed-address {$ip}; }\" >> {$dhcpVps}");
    	echo Vps::runCommand("rm -f {$dhcpVps}.backup;");
		self::restart();
    }

    /**
    * removes a host from dhcp
    * @param string $hostname
    */
    public static function remove($hostname) {
		$dhcpVps = self::getFile();
		echo Vps::runCommand("sed s#\"^host {$hostname} .*$\"#\"\"#g -i {$dhcpVps}");
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
