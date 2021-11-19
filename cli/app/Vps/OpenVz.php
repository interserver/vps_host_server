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
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --setmode restart --ipadd {$ip}"));
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
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --setmode restart --ipdel {$ips[$ip]}"));
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
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --ipdel all --ipadd {$ipNew}"));
			for ($x = 1; $x <= count($ips); $x++) {
				Vps::getLogger()->info("Adding IP {$ips[$x]} to {$vzid}");
				Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --ipadd {$ips[$x]}"));
			}
		} else {
			Vps::getLogger()->info("Removing Old IP {$ipOld} to {$vzid}");
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --ipdel {$ipOld}"));
			Vps::getLogger()->info("Adding New IP {$ipNew} to {$vzid}");
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --ipadd {$ipNew}"));
		}
		Vps::getLogger()->info("Restarting Virtual Machine '{$vzid}'");
		Vps::getLogger()->write(Vps::runCommand("vzctl restart {$vzid}"));
		return true;
	}

	public static function defineVps($vzid, $hostname, $template, $ip, $extraIps, $ram, $cpu, $hd, $password) {
		if (!file_exists('/vz/template/cache/'.$template)) { // if tempolate doesnt exist download it
			if (strpos($template, '://') !== false) { // if web url
				Vps::getLogger()->write(Vps::runCommand("wget -O /vz/template/cache/{$template} {$template}"));
			} else {
				Vps::getLogger()->write(Vps::runCommand("vztmpl-dl --gpg-check --update {$vps_os}"));
			}
		}
		$pathInfo = pathinfo($template);
		if ($pathInfo['extension'] == 'xz') { // if template is .xz recompress it to .gz
			if (file_exists('/vz/template/cache/'.str_replace('.xz', '.gz', $template))) {
				Vps::getLogger()->write("Already Exists in .gz, not changing anything");
			} else {
				Vps::getLogger()->write("Recompressing {$vps_os} to .gz");
    			Vps::getLogger()->write(Vps::runCommand("xz -d --keep '/vz/template/cache/{$template}'"));
    			$uncompressed = escapeshellarg('/vz/template/cache/'.$pathInfo['filename']);
    			Vps::getLogger()->write(Vps::runCommand("gzip -9 {$uncompressed}"));
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
		$template = str_replace(['.tar.gz', '.tar.xz'], ['', ''], $template);
		$passsword = escapeshellarg($password);
		$hostname = escapeshellarg($hostname);
		Vps::getLogger()->write(Vps::runCommand("vzctl create {$vzid} --ostemplate {$template} {$layout} {$config} --ipadd {$ip} --hostname {$hostname}", $return)); // create vps
		if ($return != 0) {
			Vps::runCommand("vzctl destroy {$vzid}");
			$layout = $layout == '--layout ploop' ? '--layout simfs' : $layout;
			Vps::getLogger()->write(Vps::runCommand("vzctl create {$vzid} --ostemplate {$template} {$layout} {$config} --ipadd {$ip} --hostname {$hostname}", $return));
		}
        @mkdir('/vz/root/'.$vzid, 0777, true);
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
		$otherSockBufB = (2561 * $numOtherSock) + $otherSockBuf;
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
		$ram = floor($ram / 1024);
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save {$force} --cpuunits {$cpuUnits} --cpus {$cpu} --diskspace {$diskSpace}:{$diskSpaceB} --numproc {$numProc}:{$numProcB} --numtcpsock {$numTcpSock}:{$numTcpSockB} --numothersock {$numOtherSock}:{$numOtherSockB} --vmguarpages {$vmGuarPages}:{$limit} --kmemsize unlimited:unlimited --tcpsndbuf {$tcpSndBuf}:{$tcpSndBufB} --tcprcvbuf {$tcpRcvBuf}:{$tcpRcvBufB} --othersockbuf {$otherSockBuf}:{$otherSockBufB} --dgramrcvbuf {$dgramRcvBuf}:{$dgramRcvBufB} --oomguarpages {$oomGuarPages}:{$limit} --privvmpages {$privVmPages}:{$privVmPagesB} --numfile {$numFile}:{$numFileB} --numflock {$numFlock}:{$numFlockB} --physpages 0:{$limit} --dcachesize {$dCacheSize}:{$dCacheSizeB} --numiptent {$numIptent}:{$numIptentB} --avnumproc {$avNumProc}:{$avNumProc} --numpty {$numPty}:{$numPtyB} --shmpages {$shmPages}:{$shmPagesB} 2>&1"));
		if (file_exists('/proc/vz/vswap')) {
			Vps::getLogger()->write(Vps::runCommand("/bin/mv -f /etc/vz/conf/{$vzid}.conf /etc/vz/conf/{$vzid}.conf.backup"));
			Vps::getLogger()->write(Vps::runCommand("grep -Ev '^(KMEMSIZE|PRIVVMPAGES)=' > /etc/vz/conf/{$vzid}.conf <  /etc/vz/conf/{$vzid}.conf.backup"));
			Vps::getLogger()->write(Vps::runCommand("/bin/rm -f /etc/vz/conf/{$vzid}.conf.backup"));
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --ram {$ram}M --swap {$ram}M --save"));
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --reset_ub"));
		}
		if (file_exists('/usr/sbin/vzcfgvalidate')) // validate vps
			Vps::getLogger()->write(Vps::runCommand("/usr/sbin/vzcfgvalidate -r /etc/vz/conf/{$vzid}.conf"));
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --devices c:1:3:rw --devices c:10:200:rw --capability net_admin:on"));
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --nameserver '8.8.8.8 64.20.34.50' --searchdomain interserver.net --onboot yes"));
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --noatime yes 2>/dev/null"));
		foreach ($extraIps as $extraIp) // setup ips
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --ipadd {$extraIp} 2>&1"));
		Vps::getLogger()->write(Vps::runCommand("vzctl start {$vzid} 2>&1"));
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --userpasswd root:{$password} 2>&1"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} mkdir -p /dev/net"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} mknod /dev/net/tun c 10 200"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} chmod 600 /dev/net/tun"));
		Vps::getLogger()->write(Vps::runCommand("/root/cpaneldirect/vzopenvztc.sh > /root/vzopenvztc.sh && sh /root/vzopenvztc.sh"));
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --userpasswd root:{$password} 2>&1"));
		$sshCnf = glob('/etc/*ssh/sshd_config');
		if (count($sshCnf) > 0) { // setup ssh
			$sshCnf = $sshCnf[0];
			if (isset($sshKey)) { // install ssh key
				Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} \"mkdir -p /root/.ssh\""));
				Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} \"echo {$ssh_key} >> /root/.ssh/authorized_keys2\""));
				Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} \"chmod go-w /root; chmod 700 /root/.ssh; chmod 600 /root/.ssh/authorized_keys2\""));
			}
			$sshCnfData = file_get_contents($sshCnf);
			if (!preg_match('/^PermitRootLogin/', $sshCnfData)) {
				Vps::getLogger()->write('Adding PermitRootLogin line to '.$sshCnf);
				$sshCnfData .= PHP_EOL.'PermitRootLogin yes'.PHP_EOL;
				file_put_contents($sshCnf, $sshCnfData);
				Vps::getLogger()->write(Vps::runCommand('kill -HUP $(vzpid $(pidof sshd) |grep "[[:space:]]'.$vzid.'[[:space:]]" | sed s#"'.$vzid.'.*ssh.*$"#""#g)'));
			} elseif (preg_match('/^PermitRootLogin (.*)$/m', $sshCnfData, $matches) && $matches[1] != 'yes') {
				Vps::getLogger()->write('Replacing PermitRootLogin line options in '.$sshCnf);
				$sshCnfData = str_replace($matches[0], str_replace($matches[1], 'yes', $matches[0]), $sshCnfData);
				file_put_contents($sshCnf, $sshCnfData);
				Vps::getLogger()->write(Vps::runCommand('kill -HUP $(vzpid $(pidof sshd) |grep "[[:space:]]'.$vzid.'[[:space:]]" | sed s#"'.$vzid.'.*ssh.*$"#""#g)'));
			}
		}
		if ($template == 'centos-7-x86_64-breadbasket') {
    		Vps::getLogger()->write("Sleeping for a minute to workaround an ish");
    		sleep(60);
    		Vps::getLogger()->write("That was a pleasant nap.. back to the grind...");
		}
		if ($template == 'centos-7-x86_64-nginxwordpress') {
			Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} /root/change.sh {$password} 2>&1"));
		}
		if ($template == 'ubuntu-15.04-x86_64-xrdp') {
			Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --userpasswd kvm:{$password} 2>&1"));
		}
		self::blockSmtp($vzid);
		return $return == 0;
	}

	public static function enableAutostart($vzid) {
		Vps::getLogger()->info('Enabling On-Boot Automatic Startup of the VPS');
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --onboot yes"));
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --disabled no"));
	}

	public static function disableAutostart($vzid) {
		Vps::getLogger()->info('Disabling On-Boot Automatic Startup of the VPS');
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --onboot no"));
		Vps::getLogger()->write(Vps::runCommand("vzctl set {$vzid} --save --disabled yes"));
	}

	public static function startVps($vzid) {
		Vps::getLogger()->info('Starting the VPS');
		Vps::getLogger()->write(Vps::runCommand("vzctl start {$vzid}"));
	}

	public static function stopVps($vzid, $fast = false) {
		Vps::getLogger()->info('Stopping the VPS');
		Vps::getLogger()->write(Vps::runCommand("vzctl stop {$vzid}"));
	}

	public static function destroyVps($vzid) {
		Vps::getLogger()->write(Vps::runCommand("vzctl destroy {$vzid}"));
	}

	public static function setupRouting($vzid, $id) {
		self::blockSmtp($vzid, $id);
	}

	public static function blockSmtp($vzid, $id = false) {
		Vps::getLogger()->write(Vps::runCommand("/admin/vzenable blocksmtp {$vzid}"));
	}

	public static function setupWebuzo($vzid) {
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y update'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y remove httpd sendmail xinetd firewalld samba samba-libs samba-common-tools samba-client samba-common samba-client-libs samba-common-libs rpcbind; userdel apache'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install nano net-tools'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'rsync -a rsync://rsync.is.cc/admin /admin;/admin/yumcron;echo \"/usr/local/emps/bin/php /usr/local/webuzo/cron.php\" > /etc/cron.daily/wu.sh && chmod +x /etc/cron.daily/wu.sh'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'wget -N http://files.webuzo.com/install.sh -O /install.sh'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'chmod +x /install.sh;bash -l /install.sh;rm -f /install.sh'"));
		Vps::getLogger()->info("Sleeping for a minute to workaround an ish");
		sleep(10);
		Vps::getLogger()->info("That was a pleasant nap.. back to the grind...");
	}

	public static function setupCpanel($vzid) {
		Vps::getLogger()->info("Sleeping for a minute to workaround an ish");
		sleep(10);
		Vps::getLogger()->info("That was a pleasant nap.. back to the grind...");
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install perl nano screen wget psmisc net-tools'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'wget http://layer1.cpanel.net/latest'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'systemctl disable firewalld.service; systemctl mask firewalld.service; rpm -e firewalld xinetd httpd'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'bash -l latest'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y remove ea-apache24-mod_ruid2'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'killall httpd; if [ -e /bin/systemctl ]; then systemctl stop httpd.service; else service httpd stop; fi'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-apache24-mod_headers ea-apache24-mod_lsapi ea-liblsapi ea-apache24-mod_env ea-apache24-mod_deflate ea-apache24-mod_expires ea-apache24-mod_suexec'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'yum -y install ea-php72-php-litespeed ea-php72-php-opcache ea-php72-php-mysqlnd ea-php72-php-mcrypt ea-php72-php-gd ea-php72-php-mbstring'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} '/usr/local/cpanel/bin/rebuild_phpconf  --default=ea-php72 --ea-php72=lsapi'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} '/usr/sbin/whmapi1 php_ini_set_directives directive-1=post_max_size%3A32M directive-2=upload_max_filesize%3A128M directive-3=memory_limit%3A256M version=ea-php72'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'cd /opt/cpanel; for i in \$(find * -maxdepth 0 -name \"ea-php*\"); do /usr/local/cpanel/bin/rebuild_phpconf --default=ea-php72 --\$i=lsapi; done'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} '/scripts/restartsrv_httpd'"));
		Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'rsync -a rsync://rsync.is.cc/admin /admin && cd /etc/cron.daily && ln -s /admin/wp/webuzo_wp_cli_auto.sh /etc/cron.daily/webuzo_wp_cli_auto.sh'"));
	}
}
