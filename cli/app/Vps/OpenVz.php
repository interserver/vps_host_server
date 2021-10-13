<?php
namespace App\Vps;

use App\Vps;
use App\Os\Xinetd;

class OpenVz
{
	public static $vpsList;


	public static function getRunningVps() {
		return explode("\n", trim(Vps::runCommand("vzlist -1 |sed s#' '#''#g")));
	}

	public static function getAllVps() {
		return explode("\n", trim(Vps::runCommand("vzlist -a -1 |sed s#' '#''#g")));
	}

	public static function vpsExists($vzid) {
		$vzid = escapeshellarg($vzid);
		/*status CTID
			Shows a container status. This is a line with five or six words, separated by spaces.
			First word is literally CTID.
			Second word is the numeric CT ID.
			Third word is showing whether this container exists or not, it can be either exist or deleted.
			Fourth word is showing the status of the container filesystem, it can be either mounted or unmounted.
			Fifth word shows if the container is running, it can be either running or down.
			Sixth word, if exists, is suspended. It appears if a dump file exists for a stopped container (see suspend).
		*/
		$return = explode(' ', trim(Vps::runCommand("vzctl status {$vzid} 2>/dev/null")));
		$exists = $return[2] == 'exist' ? true : false;
		$mounted = $return[3] == 'mounted' ? true : false;
		$runnning = $return[4] == 'running' ? true : false;
		return $exists;
	}

	public static function getList() {
		$vpsList = json_decode(Vps::runCommand("vzlist -j -a"), true);
		return $vpsList;
	}

	public static function getVps($vzid) {
		$vps = json_decode(Vps::runCommand("vzlist -j {$vzid}"), true);
		return is_null($vps) ? false : $vps[0];
	}

	public static function getVpsIps($vzid) {
		$vps = self::getVps($vzid);
		$ips = $vps['ip'];
		return $ips;
	}

	public static function addIp($vzid, $ip) {
		$ips = self::getVpsIps($vzid);
		if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $ip, $matches))
			$ip = $matches[1];
		if (in_array($ip, $ips)) {
			Vps::getLogger()->error('Skipping adding IP '.$ip.' to '.$vzid.', it already exists in the VPS.');
			return false;
		}
		Vps::getLogger()->error('Adding IP '.$ip.' to '.$vzid);
		echo Vps::runCommand("vzctl set {$vzid} --save --setmode restart --ipadd {$ip}");
		return true;
	}

	public static function removeIp($vzid, $ip) {
		$ips = self::getVpsIps($vzid);
		if (preg_match('/^([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\/[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/', $ip, $matches))
			$ip = $matches[1];
		if (!in_array($ip, $ips)) {
			Vps::getLogger()->error('Skipping removing IP '.$ip.' from '.$vzid.', it does not appear to exit in the VPS.');
			return false;
		}
		Vps::getLogger()->error('Removing IP '.$ip.' from '.$vzid);
		echo Vps::runCommand("vzctl set {$vzid} --save --setmode restart --ipdel {$ips[$ip]}");
		return true;
	}

	public static function changeIp($vzid, $ipOld, $ipNew) {
		$ips = self::getVpsIps($vzid);
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
			echo Vps::runCommand("vzctl set {$vzid} --ipdel all --ipadd {$ipNew}");
			for ($x = 1; $x <= count($ips); $x++) {
				Vps::getLogger()->info("Adding IP {$ips[$x]} to {$vzid}");
				echo Vps::runCommand("vzctl set {$vzid} --ipadd {$ips[$x]}");
			}
		} else {
			Vps::getLogger()->info("Removing Old IP {$ipOld} to {$vzid}");
			echo Vps::runCommand("vzctl set {$vzid} --ipdel {$ipOld}");
			Vps::getLogger()->info("Adding New IP {$ipNew} to {$vzid}");
			echo Vps::runCommand("vzctl set {$vzid} --ipadd {$ipNew}");
		}
		Vps::getLogger()->info("Restarting Virtual Machine '{$vzid}'");
		echo Vps::runCommand("vzctl restart {$vzid}");
		return true;
	}

	public static function defineVps($vzid, $hostname, $template, $ip, $extraIps, $ram, $cpu, $hd, $password) {
		// if tempolate doesnt exist download it
		if (!file_exists('/vz/template/cache/'.$template)) {
			// if web url
		/*
  if [ "$(echo "{$vps_os}"|grep "://")" != "" ]; then
    wget -O /vz/template/cache/{$vps_os} {$vps_os};
  else
    vztmpl-dl --gpg-check --update {$vps_os}};
  fi;
*/
		}
		// if template is .xz recompress it to .gz
		$pathInfo = pathinfo($template);
		if ($pathInfo['extension'] == 'xz') {
			if (file_exists('/vz/template/cache/'.str_replace('.xz', '.gz', $template))) {
				echo "Already Exists in .gz, not changing anything";
			} else {
				echo "Recompressing {$vps_os} to .gz";
    			// xz -d --keep "/vz/template/cache/{$vps_os}";
    			// gzip -9 "$(echo "/vz/template/cache/{$vps_os}" | sed s#"\.xz$"#""#g)";
			}
		}
		$uname = posix_uname();
		$limit = $uname['machine'] == 'x86_64' ? '9223372036854775807' : '2147483647';
		$layout = '';
		$force = '';
		if (preg_match('/vzctl set.*--force/', Vps::runCommand('vzctl'))) {
			$layout = trim(Vps::runCommand('mount |grep "^$(df /vz|tail -n 1|cut -d" " -f1)"|cut -d" " -f')) == 'ext3' || (preg_match('/^2\.6\.(\d+)/', $uname['release'], $matches) && intval($matches[1]) < 32) ? '--layout simfs' : '--layout ploop';
			$force = '--force';
		}
		$config = !file_exists('/etc/vz/conf/ve-vps.small.conf') ? '' : '--config vps.small';
		// create vps
		$template = str_replace(['.tar.gz', '.tar.xz'], ['', ''], $template);
		echo Vps::runCommand("vzctl create {$vzid} --ostemplate {$template} {$layout} {$config} --ipadd {$ip} --hostname {$hostname}", $return);
		if ($return != 0) {
			Vps::runCommand("vzctl destroy {$vzid}");
			$layout = $layout == '--layout ploop' ? '--layout simfs' : $layout;
			echo Vps::runCommand("vzctl create {$vzid} --ostemplate {$template} {$layout} {$config} --ipadd {$ip} --hostname {$hostname}", $return);
		}
        mkdir('/vz/root/'.$vzid, 0777, true);
		// set limits
		$slices = $cpu;
		$wiggle = 1000;
		$dCacheWiggle = 400000;
		$cpuUnits = 1500 * $slices;
		$avNumProc = 300 * $slices;
		$avNumProcB = $avNumProc;
		$numProc = 250 * $slices;
		$numProcB = $numProc;
		$numFlock = 8200 * $slices;
		$numFlockB = $numFlock;
		$numIptent = 2000 * $slices;
		$numIptentB = $numIptent;
		$numPty = 35 + (24 * $slices);
		$numPtyB = $numPty;
        $numTcpSock = 1800 + $slices;
        $numTcpSockB = $numTcpSock;
		$numOtherSock = 1900 * $slices;
		$numOtherSockB = $numOtherSock;
		$numFile = 32 * $avNumProc;
		$numFileB = $numFile;
		$dgramRcvBuf = 2075488 * $slices;
		$dgramRcvBufB = $dgramRcvBuf;
		$tcpRcvBuf = 8958464 * $slices;
		$tcpRcvBufB = (2561 * $numTcpSock) + $tcpRcvBuf;
		$tcpSndBuf = 8958464 * $slices;
		$tcpSndBufB = (2561 * $numTcpSock) + $tcpSndBuf;
		$otherSockBuf = 775488 * $slices;
		$otherSockBufB = (2561 * $numOtherSock) + $otherSockBu;
		$shmPages = 100000 * $slices;
		$shmPagesB = $shmPages;
		$dCacheSize = 384 * $numFile + $dCacheWiggle;
		$dCacheSizeB = 384 * $numFileB + $dCacheWiggle;
		$vmGuarPages = ((256 * 2048) * $slices) - $wiggle;
		$privVmPages = ((256 * 2048) * $slices);
		$privVmPagesB = $privVmPages + $wiggle;
		$oomGuarPages = $vmGuarPages;
		$kMemSize = (45 * 1024 * $avNumProc + $dCacheSize);
		$kMemSizeB = (45 * 1024 * $avNumProcB + $dCacheSizeB);
		$diskSpace = $hd * 1024;
		$diskSpaceB = $diskSpace;

/*
{assign var=diskspace value=1024 * 1024 * (($settings.slice_hd * $vps_slices) + $settings.additional_hd)}
{assign var=diskspace_b value=1024 * 1024 * (($settings.slice_hd * $vps_slices) + $settings.additional_hd)}
vzctl set {$vzid} --save $force --cpuunits {$cpuUnits} --cpus {$cpus} --diskspace {$diskspace}:{$diskspace_b} --numproc {$numProc}:{$numProcB} --numtcpsock {$numTcpSock}:{$numTcpSockB} --numothersock {$numOtherSock}:{$numOtherSockB} --vmguarpages {$vmguarpages}:$limit --kmemsize unlimited:unlimited --tcpsndbuf {$tcpSndBuf}:{$tcpSndBufB} --tcprcvbuf {$tcpRcvBuf}:{$tcpRcvBufB} --othersockbuf {$otherSockBuf}:{$otherSockBufB} --dgramrcvbuf {$dgramRcvBuf}:{$dgramRcvBufB} --oomguarpages {$oomguarpages}:$limit --privvmpages {$privvmpages}:{$privvmpages_b} --numfile {$numFile}:{$numFileB} --numflock {$numFlock}:{$numFlockB} --physpages 0:$limit --dcachesize {$dcachesize}:{$dcachesize_b} --numiptent {$numIptent}:{$numIptentB} --avnumproc {$avNumProc}:{$avNumProc} --numpty {$numPty}:{$numPtyB} --shmpages {$shmPages}:{$shmPagesB} 2>&1;
if [ -e /proc/vz/vswap ]; then
  /bin/mv -f /etc/vz/conf/{$vzid}.conf /etc/vz/conf/{$vzid}.conf.backup;
  grep -Ev '^(KMEMSIZE|PRIVVMPAGES)=' > /etc/vz/conf/{$vzid}.conf <  /etc/vz/conf/{$vzid}.conf.backup;
  /bin/rm -f /etc/vz/conf/{$vzid}.conf.backup;
  echo Vps::runCommand("vzctl set {$vzid} --ram {$ram}M --swap {$ram}M --save");
  echo Vps::runCommand("vzctl set {$vzid} --reset_ub");
fi;
*/
			// validate vps
			if (file_exists('/usr/sbin/vzcfgvalidate'))
				echo Vps::runCommand("/usr/sbin/vzcfgvalidate -r /etc/vz/conf/{$vzid}.conf");
			echo Vps::runCommand("vzctl set {$vzid} --save --devices c:1:3:rw --devices c:10:200:rw --capability net_admin:on");
			echo Vps::runCommand("vzctl set {$vzid} --save --nameserver '8.8.8.8 64.20.34.50' --searchdomain interserver.net --onboot yes");
			echo Vps::runCommand("vzctl set {$vzid} --save --noatime yes 2>/dev/null");
			// setup ips
			foreach ($extraIps as $extraIp)
				echo Vps::runCommand("vzctl set {$vzid} --save --ipadd {$extraIp} 2>&1");
			echo Vps::runCommand("vzctl start {$vzid} 2>&1");
			echo Vps::runCommand("vzctl set {$vzid} --save --userpasswd root:{$rootpass} 2>&1");
			echo Vps::runCommand("vzctl exec {$vzid} mkdir -p /dev/net");
			echo Vps::runCommand("vzctl exec {$vzid} mknod /dev/net/tun c 10 200");
			echo Vps::runCommand("vzctl exec {$vzid} chmod 600 /dev/net/tun");
			echo Vps::runCommand("/root/cpaneldirect/vzopenvztc.sh > /root/vzopenvztc.sh && sh /root/vzopenvztc.sh");
			echo Vps::runCommand("vzctl set {$vzid} --save --userpasswd root:{$rootpass} 2>&1");
			// setup ssh
			$sshCnf = glob('/etc/*ssh/sshd_config');
			if (countg($sshCnf) > 0) {
				$sshCnf = $sshCnf[0];
				// install ssh key
				if (isset($sshKey)) {
					echo Vps::runCommand("vzctl exec {$vzid} \"mkdir -p /root/.ssh\"");
					echo Vps::runCommand("vzctl exec {$vzid} \"echo {$ssh_key} >> /root/.ssh/authorized_keys2\"");
					echo Vps::runCommand("vzctl exec {$vzid} \"chmod go-w /root; chmod 700 /root/.ssh; chmod 600 /root/.ssh/authorized_keys2\"");
				}
/*
 if [ "$(grep "^PermitRootLogin" $sshcnf)" = "" ]; then
  echo "PermitRootLogin yes" >> $sshcnf;
  echo "Added PermitRootLogin line in $sshcnf";
  kill -HUP $(vzpid $(pidof sshd) |grep "[[:space:]]{$vzid}[[:space:]]" | sed s#"{$vzid}.*ssh.*$"#""#g);
 elif [ "$(grep "^PermitRootLogin" $sshcnf)" != "PermitRootLogin yes" ]; then
  sed s#"^PermitRootLogin.*$"#"PermitRootLogin yes"#g -i $sshcnf;
  echo "Updated PermitRootLogin line in $sshcnf";
  kill -HUP $(vzpid $(pidof sshd) |grep "[[:space:]]{$vzid}[[:space:]]" | sed s#"{$vzid}.*ssh.*$"#""#g);
 fi;
*/
			}
		// template specific stuff
		if ($template == 'centos-7-x86_64-breadbasket') {
    		echo "Sleeping for a minute to workaround an ish";
    		sleep(60);
    		echo "That was a pleasant nap.. back to the grind...";
		}
		if ($template == 'centos-7-x86_64-nginxwordpress') {
			echo Vps::runCommand("vzctl exec {$vzid} /root/change.sh {$rootpass} 2>&1");
		}
		if ($template == 'ubuntu-15.04-x86_64-xrdp') {
			echo Vps::runCommand("vzctl set {$vzid} --save --userpasswd kvm:{$rootpass} 2>&1");
		}
		echo Vps::runCommand("/admin/vzenable blocksmtp {$vzid}");




		$ram = ceil($ram / 1024);
		echo Vps::runCommand("prlctl create {$vzid} --vmtype ct --ostemplate {$template}", $return);
		$passsword = escapeshellarg($password);
		echo Vps::runCommand("prlctl set {$vzid} --userpasswd root:{$password}");
		echo Vps::runCommand("prlctl set {$vzid} --memsize {$ram}M");
		//commented out because virtuozzo says "WARNING: Use of swap significantly slows down both the container and the node."
		//echo Vps::runCommand("prlctl set {$vzid} --swappages 1G");
		$hostname = escapeshellarg($hostname);
		echo Vps::runCommand("prlctl set {$vzid} --hostname {$hostname}");
		echo Vps::runCommand("prlctl set {$vzid} --device-add net --type routed --ipadd {$ip} --nameserver 8.8.8.8");
		foreach ($extraIps as $extraIp)
			echo Vps::runCommand("prlctl set {$vzid} --ipadd {$extraIp}/255.255.255.0 2>&1");
		echo Vps::runCommand("prlctl set {$vzid} --cpus {$cpu}");
		$cpuUnits = 1500 * $cpu;
		echo Vps::runCommand("prlctl set {$vzid} --cpuunits {$cpuUnits}");
		echo Vps::runCommand("prlctl set {$vzid} --device-set hdd0 --size {$hd}");
		$hdG = ceil($hd / 1024);
		echo Vps::runCommand("vzctl set {$vzid}  --diskspace {$hdG}G --save");
		return $return == 0;
	}

	public static function getVncPort($vzid) {
		$vpsList = self::getList();
		$vncPort = '';
		foreach ($vpsList as $vps) {
			//if (!isset($vps['Hostname']))
			//echo Vps::getLogger()->info("No Hostname but got: ".json_encode($vps));
			if ($vps['ID'] == $vzid || $vps['EnvID'] == $vzid || $vps['Name'] == $vzid || (isset($vps['Hostname']) && $vps['Hostname'] == $vzid))
				if (isset($vps['Remote display']['port']))
					$vncPort = intval($vps['Remote display']['port']);
		}
		return $vncPort;
	}

	public static function setupVnc($vzid, $clientIp) {
		Vps::getLogger()->info('Setting up VNC');
		$vncPort = self::getVncPort($vzid);
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
			echo Vps::runCommand("prlctl set {$vzid} --vnc-mode manual --vnc-port {$vncPort} --vnc-nopasswd --vnc-address 127.0.0.1");
		}
		Xinetd::lock();
		if ($clientIp != '') {
			$clientIp = escapeshellarg($clientIp);
			echo Vps::runCommand("{$base}/vps_virtuozzo_setup_vnc.sh {$vzid} {$clientIp};");
		}
		echo Vps::runCommand("{$base}/vps_refresh_vnc.sh {$vzid};");
		Xinetd::unlock();
		Xinetd::restart();
	}

	public static function enableAutostart($vzid) {
		Vps::getLogger()->info('Enabling On-Boot Automatic Startup of the VPS');
		echo Vps::runCommand("vzctl set {$vzid} --save --onboot yes");
		echo Vps::runCommand("vzctl set {$vzid} --save --disabled no");
	}

	public static function disableAutostart($vzid) {
		Vps::getLogger()->info('Disabling On-Boot Automatic Startup of the VPS');
		echo Vps::runCommand("vzctl set {$vzid} --save --onboot no");
		echo Vps::runCommand("vzctl set {$vzid} --save --disabled yes");
	}

	public static function startVps($vzid) {
		Vps::getLogger()->info('Starting the VPS');
		echo Vps::runCommand("vzctl start {$vzid}");
	}

	public static function stopVps($vzid, $fast = false) {
		Vps::getLogger()->info('Stopping the VPS');
		echo Vps::runCommand("vzctl stop {$vzid}");
	}

	public static function destroyVps($vzid) {
		echo Vps::runCommand("vzctl destroy {$vzid}");
	}

	public static function setupRouting($vzid, $id) {
		self::blockSmtp($vzid, $id);
	}

	public static function blockSmtp($vzid, $id) {
		echo Vps::runCommand("/admin/vzenable blocksmtp {$vzid}");
	}

	public static function setupWebuzo($vzid) {
		echo Vps::runCommand("vzctl exec {$vzid} 'yum -y update'");
		echo Vps::runCommand("vzctl exec {$vzid} 'yum -y remove httpd sendmail xinetd firewalld samba samba-libs samba-common-tools samba-client samba-common samba-client-libs samba-common-libs rpcbind; userdel apache'");
		echo Vps::runCommand("vzctl exec {$vzid} 'yum -y install nano net-tools'");
		echo Vps::runCommand("vzctl exec {$vzid} 'rsync -a rsync://rsync.is.cc/admin /admin;/admin/yumcron;echo \"/usr/local/emps/bin/php /usr/local/webuzo/cron.php\" > /etc/cron.daily/wu.sh && chmod +x /etc/cron.daily/wu.sh'");
		echo Vps::runCommand("vzctl exec {$vzid} 'wget -N http://files.webuzo.com/install.sh -O /install.sh'");
		echo Vps::runCommand("vzctl exec {$vzid} 'chmod +x /install.sh;bash -l /install.sh;rm -f /install.sh'");
		Vps::getLogger()->info("Sleeping for a minute to workaround an ish");
		sleep(10);
		Vps::getLogger()->info("That was a pleasant nap.. back to the grind...");
	}

	public static function setupCpanel($vzid) {
		Vps::getLogger()->info("Sleeping for a minute to workaround an ish");
		sleep(10);
		Vps::getLogger()->info("That was a pleasant nap.. back to the grind...");
		echo Vps::runCommand("vzctl exec {$vzid} 'yum -y install perl nano screen wget psmisc net-tools'");
		echo Vps::runCommand("vzctl exec {$vzid} 'wget http://layer1.cpanel.net/latest'");
		echo Vps::runCommand("vzctl exec {$vzid} 'systemctl disable firewalld.service; systemctl mask firewalld.service; rpm -e firewalld xinetd httpd'");
		echo Vps::runCommand("vzctl exec {$vzid} 'bash -l latest'");
		echo Vps::runCommand("vzctl exec {$vzid} 'yum -y remove ea-apache24-mod_ruid2'");
		echo Vps::runCommand("vzctl exec {$vzid} 'killall httpd; if [ -e /bin/systemctl ]; then systemctl stop httpd.service; else service httpd stop; fi'");
		echo Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-apache24-mod_headers ea-apache24-mod_lsapi ea-liblsapi ea-apache24-mod_env ea-apache24-mod_deflate ea-apache24-mod_expires ea-apache24-mod_suexec'");
		echo Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-php72-php-litespeed ea-php72-php-opcache ea-php72-php-mysqlnd ea-php72-php-mcrypt ea-php72-php-gd ea-php72-php-mbstring'");
		echo Vps::runCommand("vzctl exec {$vzid} '/usr/local/cpanel/bin/rebuild_phpconf  --default=ea-php72 --ea-php72=lsapi'");
		echo Vps::runCommand("vzctl exec {$vzid} '/usr/sbin/whmapi1 php_ini_set_directives directive-1=post_max_size%3A32M directive-2=upload_max_filesize%3A128M directive-3=memory_limit%3A256M version=ea-php72'");
		echo Vps::runCommand("vzctl exec {$vzid} 'cd /opt/cpanel; for i in \$(find * -maxdepth 0 -name \"ea-php*\"); do /usr/local/cpanel/bin/rebuild_phpconf --default=ea-php72 --\$i=lsapi; done'");
		echo Vps::runCommand("vzctl exec {$vzid} '/scripts/restartsrv_httpd'");
		echo Vps::runCommand("vzctl exec {$vzid} 'rsync -a rsync://rsync.is.cc/admin /admin && cd /etc/cron.daily && ln -s /admin/wp/webuzo_wp_cli_auto.sh /etc/cron.daily/webuzo_wp_cli_auto.sh'");
	}
}
