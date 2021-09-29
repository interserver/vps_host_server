<?php
namespace App\Vps;

use App\Vps;
use App\Os\Xinetd;

class Virtuozzo
{
	public static $vpsList;


    public static function getRunningVps() {
		return explode("\n", trim(Vps::runCommand("prlctl list -o name|grep -v NAME")));
    }

    public static function getAllVps() {
		return explode("\n", trim(Vps::runCommand("prlctl list -a -o name|grep -v NAME")));
    }

	public static function vpsExists($hostname) {
		$hostname = escapeshellarg($hostname);
		echo Vps::runCommand('prlctl status '.$hostname.' >/dev/null 2>&1', $return);
		return $return == 0;
	}

	public static function getList() {
		$vpsList = json_decode(Vps::runCommand("prlctl list --all --info --full --json"), true);
		return $vpsList;
	}

	public static function getVps($hostname) {
		$vps = json_decode(Vps::runCommand("prlctl list --all --info --full --json {$hostname}"), true);
		return is_null($vps) ? false : $vps[0];
	}

	public static function getVpsIps($hostname) {
		$vps = self::getVps($hostname);
		$ips = explode(' ', trim($vps['Hardware']['venet0']['ips']));
		$out = [];
		foreach ($ips as $idx => $ip) {
			// strip the /netmask (ie 127.0.0.1/255.255.255.0 becomes 127.0.0.1)
			if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $ip, $matches))
				$out[$matches[1]] = $ip;
			else
				$out[$ip] = $ip;
		}
		return $out;
	}

	public static function addIp($hostname, $ip) {
		$ips = self::getVpsIps($hostname);
		if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $ip, $matches))
			$ip = $matches[1];
		if (array_key_exists($ip, $ips)) {
			Vps::getLogger()->error('Skipping adding IP '.$ip.' to '.$hostname.', it already exists in the VPS.');
			return false;
		}
		Vps::getLogger()->error('Adding IP '.$ip.' to '.$hostname);
        echo Vps::runCommand("prlctl set {$hostname} --ipadd {$ip}");
        return true;
	}

	public static function removeIp($hostname, $ip) {
		$ips = self::getVpsIps($hostname);
		if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $ip, $matches))
			$ip = $matches[1];
		if (!array_key_exists($ip, $ips)) {
			Vps::getLogger()->error('Skipping removing IP '.$ip.' from '.$hostname.', it does not appear to exit in the VPS.');
			return false;
		}
		Vps::getLogger()->error('Removing IP '.$ip.' from '.$hostname);
		echo Vps::runCommand("prlctl set {$hostname} --setmode restart --ipdel {$ips[$ip]}");
		return true;
	}

	public static function changeIp($hostname, $ipOld, $ipNew) {
		$ips = self::getVpsIps($hostname);
		$ips = array_keys($ips);
		if (in_array($ipNew, $ips)) {
			Vps::getLogger()->error('The New IP '.$ipNew.' alreaday exists as one of the IPs ('.implode(',', $ips).') for VPS '.$hostname);
			return false;
		}
		if (!in_array($ipOld, $ips)) {
			Vps::getLogger()->error('The Old IP '.$ipOld.' does not alreaday exist as one of the IPs ('.implode(',', $ips).') for VPS '.$hostname);
			return false;
		}
		if ($ipOld == $ips[0] && count($ips) > 1) {
			Vps::getLogger()->info("Changing IP from '{$ipOld}' to '{$ipNew}'");
			Vps::getLogger()->info("Removing all existing IPs and adding '{$ipNew}' to ensure it is still a primary IP");
			echo Vps::runCommand("prlctl set {$hostname} --ipdel all --ipadd {$ipNew}");
			for ($x = 1; $x <= count($ips); $x++) {
				Vps::getLogger()->info("Adding IP {$ips[$x]} to {$hostname}");
				echo Vps::runCommand("prlctl set {$hostname} --ipadd {$ips[$x]}");
			}
		} else {
			Vps::getLogger()->info("Removing Old IP {$ipOld} to {$hostname}");
			echo Vps::runCommand("prlctl set {$hostname} --ipdel {$ipOld}");
			Vps::getLogger()->info("Adding New IP {$ipNew} to {$hostname}");
			echo Vps::runCommand("prlctl set {$hostname} --ipadd {$ipNew}");
		}
		Vps::getLogger()->info("Restarting Virtual Machine '{$hostname}'");
		echo Vps::runCommand("prlctl restart {$hostname}");
		return true;
	}

	public static function defineVps($hostname, $template, $ip, $extraIps, $ram, $cpu, $hd, $password) {
		$ram = ceil($ram / 1024);
		echo Vps::runCommand("prlctl create {$hostname} --vmtype ct --ostemplate {$template}", $return);
		echo Vps::runCommand("prlctl set {$hostname} --userpasswd root:{$password}");
		echo Vps::runCommand("prlctl set {$hostname} --swappages 1G --memsize {$ram}M");
		echo Vps::runCommand("prlctl set {$hostname} --hostname {$hostname}");
		echo Vps::runCommand("prlctl set {$hostname} --device-add net --type routed --ipadd {$ip} --nameserver 8.8.8.8");
		foreach ($extraIps as $extraIp)
			echo Vps::runCommand("prlctl set {$hostname} --ipadd {$extraIp}/255.255.255.0 2>&1");
		echo Vps::runCommand("prlctl set {$hostname} --cpus {$cpu}");
		$cpuUnits = 1500 * $cpu;
		echo Vps::runCommand("prlctl set {$hostname} --cpuunits {$cpuUnits}");
		echo Vps::runCommand("prlctl set {$hostname} --device-set hdd0 --size {$hd}");
		$hdG = ceil($hd / 1024);
		echo Vps::runCommand("vzctl set {$hostname}  --diskspace {$hdG}G --save");
		return $return == 0;
	}

	public static function getVncPort($hostname) {
		$vpsList = self::getList();
		$vncPort = '';
		foreach ($vpsList as $vps)
			if ($vps['ID'] == $hostname || $vps['EnvID'] == $hostname || $vps['Name'] == $hostname || $vps['Hostname'] == $hostname)
				if (isset($vps['Remote display']['port']))
					$vncPort = intval($vps['Remote display']['port']);
		return $vncPort;
	}

	public static function setupVnc($hostname, $clientIp) {
		Vps::getLogger()->info('Setting up VNC');
		$vncPort = self::getVncPort($hostname);
		$base = Vps::$base;
		if ($vncPort == '' || $vncPort < 5901) {
			$vpsList = self::getList();
			$ports = [];
			foreach ($vpsList as $vps)
				if (isset($vps['Remote display']['port']))
					$ports[] = intval($vps['Remote display']['port']);
			$vncPort = 5901;
			while (in_array($vncPort, $ports))
				$vncPort++;
	        echo Vps::runCommand("prlctl set {$hostname} --vnc-mode manual --vnc-port {$vncPort} --vnc-nopasswd --vnc-address 127.0.0.1");
		}
		Xinetd::lock();
		if ($clientIp != '') {
			$clientIp = escapeshellarg($clientIp);
			echo Vps::runCommand("{$base}/vps_virtuozzo_setup_vnc.sh {$hostname} {$clientIp};");
		}
		echo Vps::runCommand("{$base}/vps_refresh_vnc.sh {$hostname};");
		Xinetd::unlock();
		Xinetd::restart();
	}

	public static function enableAutostart($hostname) {
		Vps::getLogger()->info('Enabling On-Boot Automatic Startup of the VPS');
		echo Vps::runCommand("prlctl set {$hostname} --onboot yes --autostart on");
		echo Vps::runCommand("prlctl set {$hostname} --enable");
	}

	public static function disableAutostart($hostname) {
		Vps::getLogger()->info('Disabling On-Boot Automatic Startup of the VPS');
		echo Vps::runCommand("prlctl set {$hostname} --onboot no --autostart off");
		echo Vps::runCommand("prlctl set {$hostname} --disable");
	}

	public static function startVps($hostname) {
		Vps::getLogger()->info('Starting the VPS');
		echo Vps::runCommand("prlctl start {$hostname}");
	}

	public static function stopVps($hostname, $fast = false) {
		Vps::getLogger()->info('Stopping the VPS');
		echo Vps::runCommand("prlctl stop {$hostname}");
	}

	public static function destroyVps($hostname) {
		echo Vps::runCommand("prlctl delete {$hostname}");
	}

	public static function setupRouting($hostname, $id) {
		self::blockSmtp($hostname, $id);
	}

	public static function blockSmtp($hostname, $id) {
		echo Vps::runCommand("/admin/vzenable blocksmtp {$hostname}");
	}

	public static function setupWebuzo($hostname) {
		echo Vps::runCommand("prlctl exec {$hostname} 'yum -y remove httpd sendmail xinetd firewalld samba samba-libs samba-common-tools samba-client samba-common samba-client-libs samba-common-libs rpcbind; userdel apache'");
		echo Vps::runCommand("prlctl exec {$hostname} 'yum -y install nano net-tools'");
		echo Vps::runCommand("prlctl exec {$hostname} 'rsync -a rsync://rsync.is.cc/admin /admin;/admin/yumcron;echo \"/usr/local/emps/bin/php /usr/local/webuzo/cron.php\" > /etc/cron.daily/wu.sh && chmod +x /etc/cron.daily/wu.sh'");
		echo Vps::runCommand("prlctl exec {$hostname} 'wget -N http://files.webuzo.com/install.sh -O /install.sh'");
		echo Vps::runCommand("prlctl exec {$hostname} 'chmod +x /install.sh;bash -l /install.sh;rm -f /install.sh'");
		Vps::getLogger()->info("Sleeping for a minute to workaround an ish");
		sleep(10);
		Vps::getLogger()->info("That was a pleasant nap.. back to the grind...");
	}

	public static function setupCpanel($hostname) {
		Vps::getLogger()->info("Sleeping for a minute to workaround an ish");
		sleep(10);
		Vps::getLogger()->info("That was a pleasant nap.. back to the grind...");
		echo Vps::runCommand("prlctl exec {$hostname} 'yum -y install perl nano screen wget psmisc net-tools'");
		echo Vps::runCommand("prlctl exec {$hostname} 'wget http://layer1.cpanel.net/latest'");
		echo Vps::runCommand("prlctl exec {$hostname} 'systemctl disable firewalld.service; systemctl mask firewalld.service; rpm -e firewalld xinetd httpd'");
		echo Vps::runCommand("prlctl exec {$hostname} 'bash -l latest'");
		echo Vps::runCommand("prlctl exec {$hostname} 'yum -y remove ea-apache24-mod_ruid2'");
		echo Vps::runCommand("prlctl exec {$hostname} 'killall httpd; if [ -e /bin/systemctl ]; then systemctl stop httpd.service; else service httpd stop; fi'");
		echo Vps::runCommand("prlctl exec {$hostname} 'yum -y install ea-apache24-mod_headers ea-apache24-mod_lsapi ea-liblsapi ea-apache24-mod_env ea-apache24-mod_deflate ea-apache24-mod_expires ea-apache24-mod_suexec'");
		echo Vps::runCommand("prlctl exec {$hostname} 'yum -y install ea-php72-php-litespeed ea-php72-php-opcache ea-php72-php-mysqlnd ea-php72-php-mcrypt ea-php72-php-gd ea-php72-php-mbstring'");
		echo Vps::runCommand("prlctl exec {$hostname} '/usr/local/cpanel/bin/rebuild_phpconf  --default=ea-php72 --ea-php72=lsapi'");
		echo Vps::runCommand("prlctl exec {$hostname} '/usr/sbin/whmapi1 php_ini_set_directives directive-1=post_max_size%3A32M directive-2=upload_max_filesize%3A128M directive-3=memory_limit%3A256M version=ea-php72'");
		echo Vps::runCommand("prlctl exec {$hostname} 'cd /opt/cpanel; for i in \$(find * -maxdepth 0 -name \"ea-php*\"); do /usr/local/cpanel/bin/rebuild_phpconf --default=ea-php72 --\$i=lsapi; done'");
		echo Vps::runCommand("prlctl exec {$hostname} '/scripts/restartsrv_httpd'");
		echo Vps::runCommand("prlctl exec {$hostname} 'rsync -a rsync://rsync.is.cc/admin /admin && cd /etc/cron.daily && ln -s /admin/wp/webuzo_wp_cli_auto.sh /etc/cron.daily/webuzo_wp_cli_auto.sh'");
	}
}
