<?php
namespace App\Vps;

use App\Vps;

class Virtuozzo
{
	public static $vpsList;


    public static function getRunningVps() {
		return explode("\n", trim(Vps::runCommand("prlctl list -o name|grep -v NAME")));
    }

	public static function vpsExists($hostname) {
		$hostname = escapeshellarg($hostname);
		echo Vps::runCommand('prlctl status '.$hostname.' >/dev/null 2>&1', $return);
		return $return == 0;
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

	public static function getList() {
		$vpsList = json_decode(Vps::runCommand("prlctl list --all --info --full --json"), true);
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
		Vps::lockXinetd();
		if ($clientIp != '') {
			$clientIp = escapeshellarg($clientIp);
			echo Vps::runCommand("{$base}/vps_virtuozzo_setup_vnc.sh {$hostname} {$clientIp};");
		}
		echo Vps::runCommand("{$base}/vps_refresh_vnc.sh {$hostname};");
		Vps::unlockXinetd();
		Vps::restartXinetd();
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
		echo Vps::runCommand("/admin/vzenable blocksmtp {$id}");
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

	public static function addIp($hostname, $ip) {
        echo Vps::runCommand("prlctl set {$hostname} --ipadd {$ip}");
	}

	public static function removeIp($hostname, $ip) {
		echo Vps::runCommand("prlctl set {$hostname} --setmode restart --ipdel {$ip}");
	}

/* vps list is an array of entries list this:
{
  "ID": "ccefa40c-5c72-4e17-94c7-d68034a1c1a5",
  "EnvID": "132694",
  "Name": "132694",
  "Description": "",
  "Type": "CT",
  "State": "running",
  "OS": "centos7",
  "Template": "no",
  "Uptime": "797400",
  "Home": "/vz/private/132694",
  "Backup path": "",
  "Owner": "root",
  "GuestTools": {
    "state": "possibly_installed"
  },
  "GuestTools autoupdate": "on",
  "Autostart": "on",
  "Autostop": "suspend",
  "Autocompact": "on",
  "Boot order": "",
  "EFI boot": "off",
  "Allow select boot device": "off",
  "External boot device": "",
  "Remote display": {
    "mode": "off",
    "address": "0.0.0.0"
  },
  "Remote display state": "stopped",
  "Hardware": {
    "cpu": {
      "sockets": 1,
      "cpus": 1,
      "cores": 1,
      "VT-x": true,
      "hotplug": true,
      "accl": "high",
      "mode": "64",
      "cpuunits": 1500,
      "ioprio": 4
    },
    "memory": {
      "size": "1024Mb",
      "hotplug": true
    },
    "video": {
      "size": "0Mb",
      "3d acceleration": "off",
      "vertical sync": "yes"
    },
    "memory_guarantee": {
      "auto": true
    },
    "hdd0": {
      "enabled": true,
      "port": "scsi:0",
      "image": "/vz/private/132694/root.hdd",
      "type": "expanded",
      "size": "25390Mb",
      "mnt": "/",
      "subtype": "virtio-scsi"
    },
    "venet0": {
      "enabled": true,
      "type": "routed",
      "ips": "216.158.239.189 "
    }
  },
  "Features": "",
  "Disabled Windows logo": "on",
  "Nested virtualization": "off",
  "Offline management": {
    "enabled": false
  },
  "Hostname": "t3.netfresn.net",
  "DNS Servers": "8.8.8.8 64.20.34.50",
  "Search Domains": "interserver.net",
  "High Availability": {
    "enabled": "yes",
    "prio": 0
  }
}
*/
}
