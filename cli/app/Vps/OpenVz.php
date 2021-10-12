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
		/*
if [ "$(echo "{$vps_os}" | grep "xz$")" != "" ]; then
  if [ -e "/vz/template/cache/$(echo "{$vps_os}" | sed s#"\.xz$"#".gz"#g)" ]; then
    echo "Already Exists in .gz, not changing anything";
  else
    echo "Recompressing {$vps_os} to .gz";
    xz -d --keep "/vz/template/cache/{$vps_os}";
    gzip -9 "$(echo "/vz/template/cache/{$vps_os}" | sed s#"\.xz$"#""#g)";
  fi;
fi;
*/
        /*
if [ "$(uname -i)" = "x86_64" ]; then
  limit=9223372036854775807
else
  limit=2147483647
fi;
*/
		/*
if [ "$(vzctl 2>&1 |grep "vzctl set.*--force")" = "" ]; then
  layout=""
  force=""
else
  if [ "$(mount | grep "^$(df /vz |grep -v ^File | cut -d" " -f1)" | cut -d" " -f5)" = "ext3" ]; then
    layout=simfs;
  else
    if [ $(echo "$(uname -r | cut -d\. -f1-2) * 10" | bc -l | cut -d\. -f1) -eq 26 ] && [ $(uname -r | cut -d\. -f3 | cut -d- -f1) -lt 32 ]; then
      layout=simfs;
    else
      layout=ploop;
    fi;
  fi;
  layout="--layout $layout";
  force="--force"
fi;
*/
		/*
if [ ! -e /etc/vz/conf/ve-vps.small.conf ] && [ -e /etc/vz/conf/ve-basic.conf-sample ]; then
  config="--config basic"
  config=""
else
  config="--config vps.small";
fi;
*/
		// create vps
/*
{assign var=ostemplate value=$vps_os|replace:'.tar.gz':''|replace:'.tar.xz':''}
/usr/sbin/vzctl create {$vzid} --ostemplate {$ostemplate} $layout $config --ipadd {$ip} --hostname {$hostname} 2>&1 || \
{
    /usr/sbin/vzctl destroy {$vzid} 2>&1;
    if [ "$layout" == "--layout ploop" ]; then
      layout="--layout simfs";
    fi;
    /usr/sbin/vzctl create {$vzid} --ostemplate {$ostemplate} $layout $config --ipadd {$ip} --hostname {$hostname} 2>&1;
};
mkdir -p /vz/root/{$vzid};
*/
		// set limits
/*
{assign var=wiggle value=1000}
{assign var=dcache_wiggle value=400000}
{assign var=cpus value=$vps_slices}
{if in_array($vps_custid, [2773, 8, 2304])} {* we privileged select few *}
{assign var=cpuunits value=1500 * 1.5 * $vps_slices}
{else}
{assign var=cpuunits value=1500 * $vps_slices}
{/if}
{assign var=diskspace value=1024 * 1024 * (($settings.slice_hd * $vps_slices) + $settings.additional_hd)}
{assign var=diskspace_b value=1024 * 1024 * (($settings.slice_hd * $vps_slices) + $settings.additional_hd)}
{* numproc, numtcpsock, and numothersock    barrier = limit *}
{assign var=avnumproc value=300 * $vps_slices}
{assign var=avnumproc_b value=$avnumproc}
{assign var=numproc value=250 * $vps_slices}
{assign var=numproc_b value=$numproc}
{assign var=numtcpsock value=1800 * $vps_slices}
{assign var=numtcpsock_b value=$numtcpsock}
{assign var=numothersock value=1900 * $vps_slices}
{assign var=numothersock_b value=$numothersock}
{* $numfile >= $avnumproc * 32 *}
{assign var=numfile value=32 * $avnumproc}
{* $numfile(bar) = $numfile(limit) *}
{assign var=numfile_b value=$numfile}
{* dcachesize(bar) >= $numfile * 384 *}
{assign var=dcachesize value=384 * $numfile + $dcache_wiggle}
{assign var=dcachesize_b value=384 * $numfile_b + $dcache_wiggle}
{* GARUNTED SLA MEMORY *}
{assign var=vmguarpages value=((256 * $settings.slice_ram) * $vps_slices) - $wiggle}
{assign var=ram value=$settings.slice_ram * $vps_slices}
{* $privvmpages >= $vmguarpages *}
{assign var=privvmpages value=((256 * $settings.slice_ram) * $vps_slices)}
{assign var=privvmpages_b value=$privvmpages + $wiggle}
{assign var=oomguarpages value=$vmguarpages}
{* kmemsize(bar) >= 40kb * avnumproc + dcachesize(lim) *}
{assign var=kmemsize value=(45 * 1024 * $avnumproc + $dcachesize)}
{assign var=kmemsize_b value=(45 * 1024 * $avnumproc_b + $dcachesize_b)}
{* dgramrcvbuf(bar) >= 129kb *}
{assign var=dgramrcvbuf value=2075488 * $vps_slices}
{assign var=dgramrcvbuf_b value=$dgramrcvbuf}
{* tcprcvbuf(bar) >= 64k *}
{assign var=tcprcvbuf value=8958464 * $vps_slices}
{* tcprcvbuf(lim) - tcprcvbuf(bar) >= 2.5KB * numtcpsock *}
{assign var=tcprcvbuf_b value=(2561 * $numtcpsock) + $tcprcvbuf}
{* tcpsndbuf(bar) >= 64k *}
{assign var=tcpsndbuf value=8958464 * $vps_slices}
{* tcpsndbuf(lim) - tcpsndbuf(bar) >= 2.5KB * numtcpsock *}
{assign var=tcpsndbuf_b value=(2561 * $numtcpsock) + $tcpsndbuf}
{* othersockbuf(bar) >= 129kb *}
{assign var=othersockbuf value=775488 * $vps_slices}
{* othersockbuf(lim) - othersockbuf(bar) >= 2.5KB * numtcpsock *}
{assign var=othersockbuf_b value=(2561 * $numothersock) + $othersockbuf}
{assign var=shmpages value=100000 * $vps_slices}
{assign var=shmpages_b value=$shmpages}
{assign var=numpty value=35 + (24 * $vps_slices)}
{assign var=numpty_b value=$numpty}
{assign var=numflock value=8200 * $vps_slices}
{assign var=numflock_b value=8200 * $vps_slices}
{* gives a like 200-300 range *}
{assign var=numiptent value=2000 * $vps_slices}
{assign var=numiptent_b value=$numiptent}
/usr/sbin/vzctl set {$vzid} \
 --save $force \
 --cpuunits {$cpuunits} \
 --cpus {$cpus} \
 --diskspace {$diskspace}:{$diskspace_b} \
 --numproc {$numproc}:{$numproc_b} \
 --numtcpsock {$numtcpsock}:{$numtcpsock_b} \
 --numothersock {$numothersock}:{$numothersock_b} \
 --vmguarpages {$vmguarpages}:$limit \
 --kmemsize unlimited:unlimited {* {$kmemsize}:{$kmemsize_b} *} \
 --tcpsndbuf {$tcpsndbuf}:{$tcpsndbuf_b} \
 --tcprcvbuf {$tcprcvbuf}:{$tcprcvbuf_b} \
 --othersockbuf {$othersockbuf}:{$othersockbuf_b} \
 --dgramrcvbuf {$dgramrcvbuf}:{$dgramrcvbuf_b} \
 --oomguarpages {$oomguarpages}:$limit \
 --privvmpages {$privvmpages}:{$privvmpages_b} \
 --numfile {$numfile}:{$numfile_b} \
 --numflock {$numflock}:{$numflock_b} {* unlimited:unlimited *} \
 --physpages 0:$limit \
 --dcachesize {$dcachesize}:{$dcachesize_b} \
 --numiptent {$numiptent}:{$numiptent_b} \
 --avnumproc {$avnumproc}:{$avnumproc_b} \
 --numpty {$numpty}:{$numpty_b} \
 --shmpages {$shmpages}:{$shmpages_b} 2>&1;
if [ -e /proc/vz/vswap ]; then
  /bin/mv -f /etc/vz/conf/{$vzid}.conf /etc/vz/conf/{$vzid}.conf.backup;
#  grep -Ev '^(KMEMSIZE|LOCKEDPAGES|PRIVVMPAGES|SHMPAGES|NUMPROC|PHYSPAGES|VMGUARPAGES|OOMGUARPAGES|NUMTCPSOCK|NUMFLOCK|NUMPTY|NUMSIGINFO|TCPSNDBUF|TCPRCVBUF|OTHERSOCKBUF|DGRAMRCVBUF|NUMOTHERSOCK|DCACHESIZE|NUMFILE|AVNUMPROC|NUMIPTENT|ORIGIN_SAMPLE|SWAPPAGES)=' > /etc/vz/conf/{$vzid}.conf <  /etc/vz/conf/{$vzid}.conf.backup;
  grep -Ev '^(KMEMSIZE|PRIVVMPAGES)=' > /etc/vz/conf/{$vzid}.conf <  /etc/vz/conf/{$vzid}.conf.backup;
  /bin/rm -f /etc/vz/conf/{$vzid}.conf.backup;
  /usr/sbin/vzctl set {$vzid} --ram {$ram}M --swap {$ram}M --save;
  /usr/sbin/vzctl set {$vzid} --reset_ub;
fi;
*/
			// validate vps
/*
if [ -e /usr/sbin/vzcfgvalidate ]; then
 /usr/sbin/vzcfgvalidate -r /etc/vz/conf/{$vzid}.conf;
fi;
*/
/*
/usr/sbin/vzctl set {$vzid} --save --devices c:1:3:rw --devices c:10:200:rw --capability net_admin:on;
/usr/sbin/vzctl set {$vzid} --save --nameserver '8.8.8.8 64.20.34.50' --searchdomain interserver.net --onboot yes;
/usr/sbin/vzctl set {$vzid} --save --noatime yes 2>/dev/null;
*/
			// setup ips
/*
{foreach item=extraip from=$extraips}
/usr/sbin/vzctl set {$vzid} --save --ipadd {$extraip} 2>&1;
{/foreach}
*/
/*
/usr/sbin/vzctl start {$vzid} 2>&1;
/usr/sbin/vzctl set {$vzid} --save --userpasswd root:{$rootpass} 2>&1;
/usr/sbin/vzctl exec {$vzid} mkdir -p /dev/net;
/usr/sbin/vzctl exec {$vzid} mknod /dev/net/tun c 10 200;
/usr/sbin/vzctl exec {$vzid} chmod 600 /dev/net/tun;
/root/cpaneldirect/vzopenvztc.sh > /root/vzopenvztc.sh && sh /root/vzopenvztc.sh;
/usr/sbin/vzctl set {$vzid} --save --userpasswd root:{$rootpass} 2>&1;
*/
		// setup ssh
		// install ssh key
/*
sshcnf="$(find /vz/root/{$vzid}/etc/*ssh/sshd_config 2>/dev/null)";
if [ -e "$sshcnf" ]; then
{if isset($ssh_key)}
 vzctl exec {$vzid} "mkdir -p /root/.ssh;"
 vzctl exec {$vzid} "echo {$ssh_key} >> /root/.ssh/authorized_keys2;"
 vzctl exec {$vzid} "chmod go-w /root; chmod 700 /root/.ssh; chmod 600 /root/.ssh/authorized_keys2;"
{/if}
 if [ "$(grep "^PermitRootLogin" $sshcnf)" = "" ]; then
  echo "PermitRootLogin yes" >> $sshcnf;
  echo "Added PermitRootLogin line in $sshcnf";
  kill -HUP $(vzpid $(pidof sshd) |grep "[[:space:]]{$vzid}[[:space:]]" | sed s#"{$vzid}.*ssh.*$"#""#g);
 elif [ "$(grep "^PermitRootLogin" $sshcnf)" != "PermitRootLogin yes" ]; then
  sed s#"^PermitRootLogin.*$"#"PermitRootLogin yes"#g -i $sshcnf;
  echo "Updated PermitRootLogin line in $sshcnf";
  kill -HUP $(vzpid $(pidof sshd) |grep "[[:space:]]{$vzid}[[:space:]]" | sed s#"{$vzid}.*ssh.*$"#""#g);
 fi;
fi;
*/
		// template specific stuff
/*
if [ "{$ostemplate}" = "centos-7-x86_64-breadbasket" ]; then
    echo "Sleeping for a minute to workaround an ish"
    sleep 1m;
    echo "That was a pleasant nap.. back to the grind..."
fi;

if [ "{$ostemplate}" = "centos-7-x86_64-nginxwordpress" ]; then
    vzctl exec {$vzid} /root/change.sh {$rootpass} 2>&1;
fi;

if [ "{$ostemplate}" = "ubuntu-15.04-x86_64-xrdp" ]; then
    /usr/sbin/vzctl set {$vzid} --save --userpasswd kvm:{$rootpass} 2>&1;
fi;
*/
		// /admin/vzenable blocksmtp {$vzid}

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
