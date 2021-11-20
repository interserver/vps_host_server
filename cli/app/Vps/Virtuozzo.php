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

	public static function vpsExists($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("prlctl status {$vzid} >/dev/null 2>&1", $return));
		return $return == 0;
	}

	public static function getList() {
		$vpsList = json_decode(Vps::runCommand("prlctl list --all --info --full --json"), true);
		return $vpsList;
	}

	public static function getVps($vzid) {
		$vps = json_decode(Vps::runCommand("prlctl list --all --info --full --json {$vzid}"), true);
		return is_null($vps) ? false : $vps[0];
	}

	public static function getVpsIps($vzid, $simple = false) {
		$vps = self::getVps($vzid);
		$ips = explode(' ', trim($vps['Hardware']['venet0']['ips']));
		$out = [];
		$matchups = [];
		foreach ($ips as $idx => $ip) {
			$simpleIp = $ip;
			// strip the /netmask (ie 127.0.0.1/255.255.255.0 becomes 127.0.0.1)
			if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $ip, $matches)) {
				$simpleIp = $matches[1];
			}
			$matchups[$simpleIp] = $ip;
			$out[] = $simpleIp;
		}
		return $simple === false ? $matchups : $out;
	}

	public static function addIp($vzid, $ip) {
		$ips = self::getVpsIps($vzid);
		if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $ip, $matches))
			$ip = $matches[1];
		if (array_key_exists($ip, $ips)) {
			Vps::getLogger()->error('Skipping adding IP '.$ip.' to '.$vzid.', it already exists in the VPS.');
			return false;
		}
		Vps::getLogger()->error('Adding IP '.$ip.' to '.$vzid);
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --ipadd {$ip}"));
		return true;
	}

	public static function removeIp($vzid, $ip) {
		$ips = self::getVpsIps($vzid);
		if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $ip, $matches))
			$ip = $matches[1];
		if (!array_key_exists($ip, $ips)) {
			Vps::getLogger()->error('Skipping removing IP '.$ip.' from '.$vzid.', it does not appear to exit in the VPS.');
			return false;
		}
		Vps::getLogger()->error('Removing IP '.$ip.' from '.$vzid);
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --setmode restart --ipdel {$ips[$ip]}"));
		return true;
	}

	public static function changeIp($vzid, $ipOld, $ipNew) {
		$ips = self::getVpsIps($vzid, true);
		if (in_array($ipNew, $ips)) {
			Vps::getLogger()->error('The New IP '.$ipNew.' alreaday exists as one of the IPs ('.implode(',', $ips).') for VPS '.$vzid);
			return false;
		}
		if (!in_array($ipOld, $ips)) {
			Vps::getLogger()->error('The Old IP '.$ipOld.' does not alreaday exist as one of the IPs ('.implode(',', $ips).') for VPS '.$vzid);
			return false;
		}
		if ($ipOld == $ips[0] && count($ips) > 1) {
			Vps::getLogger()->info("Changing IP from '{$ipOld}' to '{$ipNew}'");
			Vps::getLogger()->info("Removing all existing IPs and adding '{$ipNew}' to ensure it is still a primary IP");
			Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --ipdel all --ipadd {$ipNew}"));
			for ($x = 1; $x <= count($ips); $x++) {
				Vps::getLogger()->info("Adding IP {$ips[$x]} to {$vzid}");
				Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --ipadd {$ips[$x]}"));
			}
		} else {
			Vps::getLogger()->info("Removing Old IP {$ipOld} to {$vzid}");
			Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --ipdel {$ipOld}"));
			Vps::getLogger()->info("Adding New IP {$ipNew} to {$vzid}");
			Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --ipadd {$ipNew}"));
		}
		Vps::getLogger()->info("Restarting Virtual Machine '{$vzid}'");
		Vps::getLogger()->write(Vps::runCommand("prlctl restart {$vzid}"));
		return true;
	}

	public static function defineVps($vzid, $hostname, $template, $ip, $extraIps, $ram, $cpu, $hd, $password) {
		$ram = ceil($ram / 1024);
		Vps::getLogger()->write(Vps::runCommand("prlctl create {$vzid} --vmtype ct --ostemplate {$template}", $return));
		$passsword = escapeshellarg($password);
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --userpasswd root:{$password}"));
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --memsize {$ram}M"));
		//commented out because virtuozzo says "WARNING: Use of swap significantly slows down both the container and the node."
		//Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --swappages 1G"));
		$hostname = escapeshellarg($hostname);
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --hostname {$hostname}"));
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --device-add net --type routed --ipadd {$ip} --nameserver 8.8.8.8"));
		foreach ($extraIps as $extraIp)
			Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --ipadd {$extraIp}/255.255.255.0 2>&1"));
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --cpus {$cpu}"));
		$cpuUnits = 1500 * $cpu;
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --cpuunits {$cpuUnits}"));
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --device-set hdd0 --size {$hd}"));
		$hdG = ceil($hd / 1024);
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid}  --diskspace {$hdG}G --save"));
		return $return == 0;
	}

	public static function getVpsRemotes($vzid) {
		$vps = self::getVps($vzid);
		$remotes = [];
		if (isset($vps['Remote display']['port']))
			$remotes['vnc'] = intval($vps['Remote display']['port']);
        if (count($remotes) == 0) {
			$vpsList = self::getList();
			$ports = [];
			foreach ($vpsList as $vps)
				if (isset($vps['Remote display']['port']))
					$ports[] = intval($vps['Remote display']['port']);
			$vncPort = 5901;
			while (in_array($vncPort, $ports))
				$vncPort++;
			Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --vnc-mode manual --vnc-port {$vncPort} --vnc-nopasswd --vnc-address 127.0.0.1"));
			$remotes['vnc'] = $vncPort;
		}
		return $remotes;
	}

	public static function getVncPort($vzid) {
		$vpsList = self::getList();
		$vncPort = '';
		foreach ($vpsList as $vps) {
			//if (!isset($vps['Hostname']))
			//	Vps::getLogger()->info("No Hostname but got: ".json_encode($vps));
			if ($vps['ID'] == $vzid || $vps['EnvID'] == $vzid || $vps['Name'] == $vzid || (isset($vps['Hostname']) && $vps['Hostname'] == $vzid))
				if (isset($vps['Remote display']['port']))
					$vncPort = intval($vps['Remote display']['port']);
		}
		return $vncPort;
	}

	public static function enableAutostart($vzid) {
		Vps::getLogger()->info('Enabling On-Boot Automatic Startup of the VPS');
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --onboot yes --autostart on"));
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --enable"));
	}

	public static function disableAutostart($vzid) {
		Vps::getLogger()->info('Disabling On-Boot Automatic Startup of the VPS');
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --onboot no --autostart off"));
		Vps::getLogger()->write(Vps::runCommand("prlctl set {$vzid} --disable"));
	}

	public static function startVps($vzid) {
		Vps::getLogger()->info('Starting the VPS');
		Vps::getLogger()->write(Vps::runCommand("prlctl start {$vzid}"));
	}

	public static function stopVps($vzid, $fast = false) {
		Vps::getLogger()->info('Stopping the VPS');
		Vps::getLogger()->write(Vps::runCommand("prlctl stop {$vzid}"));
	}

	public static function destroyVps($vzid) {
		Vps::getLogger()->write(Vps::runCommand("prlctl delete {$vzid}"));
	}

	public static function setupRouting($vzid, $id) {
		self::blockSmtp($vzid, $id);
	}

	public static function blockSmtp($vzid, $id) {
		Vps::getLogger()->write(Vps::runCommand("/admin/vzenable blocksmtp {$vzid}"));
	}

	public static function setupWebuzo($vzid) {
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'yum -y update'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'yum -y remove httpd sendmail xinetd firewalld samba samba-libs samba-common-tools samba-client samba-common samba-client-libs samba-common-libs rpcbind; userdel apache'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'yum -y install nano net-tools'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'rsync -a rsync://rsync.is.cc/admin /admin;/admin/yumcron;echo \"/usr/local/emps/bin/php /usr/local/webuzo/cron.php\" > /etc/cron.daily/wu.sh && chmod +x /etc/cron.daily/wu.sh'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'wget -N http://files.webuzo.com/install.sh -O /install.sh'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'chmod +x /install.sh;bash -l /install.sh;rm -f /install.sh'"));
		Vps::getLogger()->info("Sleeping for a minute to workaround an ish");
		sleep(10);
		Vps::getLogger()->info("That was a pleasant nap.. back to the grind...");
	}

	public static function setupCpanel($vzid) {
		Vps::getLogger()->info("Sleeping for a minute to workaround an ish");
		sleep(10);
		Vps::getLogger()->info("That was a pleasant nap.. back to the grind...");
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'yum -y install perl nano screen wget psmisc net-tools'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'wget http://layer1.cpanel.net/latest'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'systemctl disable firewalld.service; systemctl mask firewalld.service; rpm -e firewalld xinetd httpd'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'bash -l latest'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'yum -y remove ea-apache24-mod_ruid2'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'killall httpd; if [ -e /bin/systemctl ]; then systemctl stop httpd.service; else service httpd stop; fi'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'yum -y install ea-apache24-mod_headers ea-apache24-mod_lsapi ea-liblsapi ea-apache24-mod_env ea-apache24-mod_deflate ea-apache24-mod_expires ea-apache24-mod_suexec'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'yum -y install ea-php72-php-litespeed ea-php72-php-opcache ea-php72-php-mysqlnd ea-php72-php-mcrypt ea-php72-php-gd ea-php72-php-mbstring'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} '/usr/local/cpanel/bin/rebuild_phpconf  --default=ea-php72 --ea-php72=lsapi'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} '/usr/sbin/whmapi1 php_ini_set_directives directive-1=post_max_size%3A32M directive-2=upload_max_filesize%3A128M directive-3=memory_limit%3A256M version=ea-php72'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'cd /opt/cpanel; for i in \$(find * -maxdepth 0 -name \"ea-php*\"); do /usr/local/cpanel/bin/rebuild_phpconf --default=ea-php72 --\$i=lsapi; done'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} '/scripts/restartsrv_httpd'"));
		Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'rsync -a rsync://rsync.is.cc/admin /admin && cd /etc/cron.daily && ln -s /admin/wp/webuzo_wp_cli_auto.sh /etc/cron.daily/webuzo_wp_cli_auto.sh'"));
	}
}
