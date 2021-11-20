<?php
namespace App\Vps;

use App\XmlToArray;
use App\Vps;
use App\Os\Dhcpd;
use App\Os\Xinetd;

class Kvm
{
	public static function getRunningVps() {
		return explode("\n", trim(Vps::runCommand("virsh list --name")));
	}

	public static function getAllVps() {
		return explode("\n", trim(Vps::runCommand("virsh list --all --name")));
	}

	public static function vpsExists($vzid) {
		$vzid = escapeshellarg($vzid);
		Vps::getLogger()->write(Vps::runCommand("virsh dominfo {$vzid} >/dev/null 2>&1", $return));
		return $return == 0;
	}

	public static function getPool() {
		$pool = XmlToArray::go(trim(Vps::runCommand("virsh pool-dumpxml vz 2>/dev/null")));
		return $pool;
	}

	public static function getPoolType() {
		$pool = self::getPool()['pool_attr']['type'];
		if ($pool == '') {
			$base = Vps::$base;
			Vps::getLogger()->write(Vps::runCommand("{$base}/create_libvirt_storage_pools.sh"));
			$pool = self::getPool()['pool_attr']['type'];
		}
		if (preg_match('/vz/', Vps::runCommand("virsh pool-list --inactive"))) {
			Vps::getLogger()->write(Vps::runCommand("virsh pool-start vz;"));
		}
		return $pool;
	}

	/**
	* gets the vps details in xml format
	*
	* @param string $vzid vps identifier
	* @return string xml formatted vps informatoin
	*/
	public static function getVpsXml($vzid) {
		$vzid = escapeshellarg($vzid);
		$xml = trim(Vps::runCommand("virsh dumpxml {$vzid};"));
		return $xml;
	}

	/**
	* gets the vps details in an array
	*
	* @param string $vzid vps identifier
	* @return array the array of vps information
	*/
	public static function getVps($vzid) {
		$xml = self::getVpsXml($vzid);
		$vps = XmlToArray::go($xml);
		return $vps;
	}

	public static function getVpsMac($vzid) {
		$mac = self::getVps($vzid)['domain']['devices']['interface']['mac_attr']['address'];
		return $mac;
	}

	public static function getVpsIps($vzid) {
		$params = self::getVps($vzid)['domain']['devices']['interface']['filterref']['parameter'];
		$ips = [];
		foreach ($params as $idx => $data) {
			if (array_key_exists('name', $data) && $data['name'] == 'IP') {
				$ips[] = $data['value'];
			}
		}
		return $ips;
	}

	public static function addIp($vzid, $ip) {
		$ips = self::getVpsIps($vzid);
		if (in_array($ip, $ips)) {
			Vps::getLogger()->error('Skipping adding IP '.$ip.' to '.$vzid.', it already exists in the VPS.');
			return false;
		}
		Vps::getLogger()->error('Adding IP '.$ip.' to '.$vzid);
		Vps::getLogger()->write(Vps::runCommand("virsh dumpxml --inactive --security-info {$vzid} > {$vzid}.xml"));
		Vps::getLogger()->write(Vps::runCommand("sed s#\"</filterref>\"#\"  <parameter name='IP' value='{$ip}'/>\\n    </filterref>\"#g -i {$vzid}.xml"));
		Vps::getLogger()->write(Vps::runCommand("virsh define {$vzid}.xml"));
		Vps::getLogger()->write(Vps::runCommand("rm -f {$vzid}.xml"));
	}

	public static function removeIp($vzid, $ip) {
		$ips = self::getVpsIps($vzid);
		if (!in_array($ip, $ips)) {
			Vps::getLogger()->error('Skipping removing IP '.$ip.' from '.$vzid.', it does not appear to exit in the VPS.');
			return false;
		}
		Vps::getLogger()->error('Removing IP '.$ip.' from '.$vzid);
		Vps::getLogger()->write(Vps::runCommand("virsh dumpxml --inactive --security-info {$vzid} | grep -v \"value='{$ip}'\" > {$vzid}.xml"));
		Vps::getLogger()->write(Vps::runCommand("virsh define {$vzid}.xml"));
		Vps::getLogger()->write(Vps::runCommand("rm -f {$vzid}.xml"));
	}

	public static function defineVps($vzid, $hostname, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $maxRam, $maxCpu, $useAll) {
		Vps::getLogger()->info('Creating VPS Definition');
		$base = Vps::$base;
		Vps::getLogger()->indent();
		if (self::vpsExists($vzid)) {
			Vps::getLogger()->write(Vps::runCommand("virsh destroy {$vzid}"));
			Vps::getLogger()->write(Vps::runCommand("virsh dumpxml {$vzid} > {$vzid}.xml"));
			Vps::getLogger()->write(Vps::runCommand("/bin/cp -f {$vzid}.xml {$vzid}.xml.backup"));
			Vps::getLogger()->write(Vps::runCommand("virsh undefine {$vzid}"));
			Vps::getLogger()->write(Vps::runCommand("mv -f {$vzid}.xml.backup {$vzid}.xml"));
		} else {
			if ($pool != 'zfs') {
				Vps::getLogger()->debug('Removing UUID Filterref and IP information');
				Vps::getLogger()->write(Vps::runCommand("grep -v -e uuid -e filterref -e \"<parameter name='IP'\" {$base}/windows.xml | sed s#\"windows\"#\"{$vzid}\"#g > {$vzid}.xml"));
			} else {
				Vps::getLogger()->debug('Removing UUID information');
				Vps::getLogger()->write(Vps::runCommand("grep -v -e uuid {$base}/windows.xml | sed -e s#\"windows\"#\"{$vzid}\"#g -e s#\"/dev/vz/{$vzid}\"#\"{$device}\"#g > {$vzid}.xml"));
			}
			if (!file_exists('/usr/libexec/qemu-kvm') && file_exists('/usr/bin/kvm')) {
				Vps::getLogger()->debug('Replacing KVM Binary Path');
				Vps::getLogger()->write(Vps::runCommand("sed s#\"/usr/libexec/qemu-kvm\"#\"/usr/bin/kvm\"#g -i {$vzid}.xml"));
			}
		}
		if ($useAll == true) {
			Vps::getLogger()->debug('Removing IP information');
			Vps::getLogger()->write(Vps::runCommand("sed -e s#\"^.*<parameter name='IP.*$\"#\"\"#g -e  s#\"^.*filterref.*$\"#\"\"#g -i {$vzid}.xml"));
		} else {
			Vps::getLogger()->debug('Replacing UUID Filterref and IP information');
			$repl = "<parameter name='IP' value='{$ip}'/>";
			if (count($extraIps) > 0)
				foreach ($extraIps as $extraIp)
					$repl = "{$repl}\\n        <parameter name='IP' value='{$extraIp}'/>";
			Vps::getLogger()->write(Vps::runCommand("sed s#\"<parameter name='IP' value.*/>\"#\"{$repl}\"#g -i {$vzid}.xml;"));
		}
		if ($mac != '') {
			Vps::getLogger()->debug('Replacing MAC addresss');
			Vps::getLogger()->write(Vps::runCommand("sed s#\"<mac address='.*'\"#\"<mac address='{$mac}'\"#g -i {$vzid}.xml"));
		} else {
			Vps::getLogger()->debug('Removing MAC address');
			Vps::getLogger()->write(Vps::runCommand("sed s#\"^.*<mac address.*$\"#\"\"#g -i {$vzid}.xml"));
		}
		Vps::getLogger()->debug('Setting CPU limits');
		Vps::getLogger()->write(Vps::runCommand("sed s#\"<\(vcpu.*\)>.*</vcpu>\"#\"<vcpu placement='static' current='{$cpu}'>{$maxCpu}</vcpu>\"#g -i {$vzid}.xml;"));
		Vps::getLogger()->debug('Setting Max Memory limits');
		Vps::getLogger()->write(Vps::runCommand("sed s#\"<memory.*memory>\"#\"<memory unit='KiB'>{$maxRam}</memory>\"#g -i {$vzid}.xml;"));
		Vps::getLogger()->debug('Setting Memory limits');
		Vps::getLogger()->write(Vps::runCommand("sed s#\"<currentMemory.*currentMemory>\"#\"<currentMemory unit='KiB'>{$ram}</currentMemory>\"#g -i {$vzid}.xml;"));
		if (trim(Vps::runCommand("grep -e \"flags.*ept\" -e \"flags.*npt\" /proc/cpuinfo")) != '') {
			Vps::getLogger()->debug('Adding HAP features flag');
			Vps::getLogger()->write(Vps::runCommand("sed s#\"<features>\"#\"<features>\\n    <hap/>\"#g -i {$vzid}.xml;"));
		}
		if (trim(Vps::runCommand("date \"+%Z\"")) == 'PDT') {
			Vps::getLogger()->debug('Setting Timezone to PST');
			Vps::getLogger()->write(Vps::runCommand("sed s#\"America/New_York\"#\"America/Los_Angeles\"#g -i {$vzid}.xml;"));
		}
		if (file_exists('/etc/lsb-release')) {
			if (substr($template, 0, 7) == 'windows') {
				Vps::getLogger()->debug('Adding HyperV block');
				Vps::getLogger()->write(Vps::runCommand("sed -e s#\"</features>\"#\"  <hyperv>\\n      <relaxed state='on'/>\\n      <vapic state='on'/>\\n      <spinlocks state='on' retries='8191'/>\\n    </hyperv>\\n  </features>\"#g -i {$vzid}.xml;"));
				Vps::getLogger()->debug('Adding HyperV timer');
				Vps::getLogger()->write(Vps::runCommand("sed -e s#\"<clock offset='timezone' timezone='\([^']*\)'/>\"#\"<clock offset='timezone' timezone='\\1'>\\n    <timer name='hypervclock' present='yes'/>\\n  </clock>\"#g -i {$vzid}.xml;"));
			}
			Vps::getLogger()->debug('Customizing SCSI controller');
			Vps::getLogger()->write(Vps::runCommand("sed s#\"\(<controller type='scsi' index='0'.*\)>\"#\"\\1 model='virtio-scsi'>\\n      <driver queues='{$cpu}'/>\"#g -i  {$vzid}.xml;"));
		}
		Vps::getLogger()->write(Vps::runCommand("virsh define {$vzid}.xml", $return));
		Vps::getLogger()->write(Vps::runCommand("rm -f {$vzid}.xml"));
		//Vps::getLogger()->write(Vps::runCommand("virsh setmaxmem {$vzid} $maxRam;"));
		//Vps::getLogger()->write(Vps::runCommand("virsh setmem {$vzid} $ram;"));
		//Vps::getLogger()->write(Vps::runCommand("virsh setvcpus {$vzid} $cpu;"));
		Vps::getLogger()->unIndent();
		Dhcpd::setup($vzid, $ip, $mac);
		return $return == 0;
	}

	public static function runBuildEbtables() {
		if (Vps::getPoolType() != 'zfs') {
			$base = Vps::$base;
			Vps::getLogger()->write(Vps::runCommand("bash {$base}/run_buildebtables.sh"));
		}
	}

	public static function setupCgroups($vzid, $slices) {
		if (file_exists('/cgroup/blkio/libvirt/qemu')) {
			Vps::getLogger()->info('Setting up CGroups');
			$cpushares = $slices * 512;
			$ioweight = 400 + (37 * $slices);
			Vps::getLogger()->write(Vps::runCommand("virsh schedinfo {$vzid} --set cpu_shares={$cpushares} --current;"));
			Vps::getLogger()->write(Vps::runCommand("virsh schedinfo {$vzid} --set cpu_shares={$cpushares} --config;"));
			Vps::getLogger()->write(Vps::runCommand("virsh blkiotune {$vzid} --weight {$ioweight} --current;"));
			Vps::getLogger()->write(Vps::runCommand("virsh blkiotune {$vzid} --weight {$ioweight} --config;"));
		}
	}

	public static function getVpsRemotes($vzid) {
		$xml = self::getVpsXml($vzid);
		$remotes = [];
		if (preg_match_all('/<graphics type=\'([^\']+)\'\s?.*\sport=\'([^\']+)\'/muU', $xml, $matches)) {
			foreach ($matches[1] as $idx => $type) {
				$port = $matches[2][$idx];
				if (is_numeric($port))
					$port = intval($port);
				if (in_array($port, ['-1', '' ,'0']))
					continue;
				$remotes[$type] = $port;
			}
		}
		return $remotes;
	}

	public static function  getVncPort($vzid) {
		$vncPort = trim(Vps::runCommand("virsh vncdisplay {$vzid} | cut -d: -f2 | head -n 1"));
		if ($vncPort == '') {
			sleep(2);
			$vncPort = trim(Vps::runCommand("virsh vncdisplay {$vzid} | cut -d: -f2 | head -n 1"));
			if ($vncPort == '') {
				sleep(2);
				$vncPort = trim(Vps::runCommand("virsh dumpxml {$vzid} |grep -i 'graphics type=.vnc.' | cut -d\' -f4"));
			} else {
				$vncPort += 5900;
			}
		} else {
			$vncPort += 5900;
		}
		return is_numeric($vncPort) ? intval($vncPort) : $vncPort;
	}

	public static function setupStorage($vzid, $device, $pool, $hd) {
		Vps::getLogger()->info('Creating Storage Pool');
		$base = Vps::$base;
		if ($pool == 'zfs') {
			Vps::getLogger()->write(Vps::runCommand("zfs create vz/{$vzid}"));
			@mkdir('/vz/'.$vzid, 0777, true);
			while (!file_exists('/vz/'.$vzid)) {
				sleep(1);
			}
			//virsh vol-create-as --pool vz --name {$vzid}/os.qcow2 --capacity "$hd"M --format qcow2 --prealloc-metadata
			//sleep 5s;
			//device="$(virsh vol-list vz --details|grep " {$vzid}[/ ]"|awk '{ print $2 }')"
		} else {
			Vps::getLogger()->write(Vps::runCommand("{$base}/vps_kvm_lvmcreate.sh {$vzid} {$hd}"));
			// exit here on failed exit status
		}
		Vps::getLogger()->info("{$pool} pool device {$device} created");
	}

	public static function removeStorage($vzid) {
		$pool = Vps::getPoolType();
		if ($pool == 'zfs') {
			Vps::getLogger()->write(Vps::runCommand("zfs list -t snapshot|grep \"/{$vzid}@\"|cut -d\" \" -f1|xargs -r -n 1 zfs destroy -v"));
			Vps::getLogger()->write(Vps::runCommand("virsh vol-delete --pool vz/os.qcow2 {$vzid} 2>/dev/null"));
			Vps::getLogger()->write(Vps::runCommand("virsh vol-delete --pool vz {$vzid}"));
			Vps::getLogger()->write(Vps::runCommand("zfs destroy vz/{$vzid}"));
			if (file_exists('/vz/'.$vzid))
				rmdir('/vz/'.$vzid);
		} else {
			Vps::getLogger()->write(Vps::runCommand("kpartx -dv /dev/vz/{$vzid}"));
			Vps::getLogger()->write(Vps::runCommand("lvremove -f /dev/vz/{$vzid}"));
		}
	}

	public static function enableAutostart($vzid) {
		Vps::getLogger()->info('Enabling On-Boot Automatic Startup of the VPS');
		Vps::getLogger()->write(Vps::runCommand("virsh autostart {$vzid}"));
	}

	public static function disableAutostart($vzid) {
		Vps::getLogger()->info('Disabling On-Boot Automatic Startup of the VPS');
		Vps::getLogger()->write(Vps::runCommand("virsh autostart --disable {$vzid}"));
	}

	public static function startVps($vzid) {
		Vps::getLogger()->info('Starting the VPS');
		Xinetd::remove($vzid);
		Xinetd::restart();
		Vps::getLogger()->write(Vps::runCommand("virsh start {$vzid}"));
		self::runBuildEbtables();
	}

	public static function stopVps($vzid, $fast = false) {
		Vps::getLogger()->info('Stopping the VPS');
		Vps::getLogger()->indent();
		$stopped = false;
		if ($fast === false) {
			Vps::getLogger()->info('Sending Softwawre Power-Off');
			Vps::getLogger()->write(Vps::runCommand("virsh shutdown {$vzid}"));
			$waited = 0;
			$maxWait = 120;
			$sleepTime = 5;
			while ($waited <= $maxWait && $stopped == false) {
				if (Vps::isVpsRunning($vzid)) {
					Vps::getLogger()->info('still running, waiting (waited '.$waited.'/'.$maxWait.' seconds)');
					sleep($sleepTime);
					$waited += $sleepTime;
					if ($waited % 15 == 0)
						Vps::runCommand("virsh shutdown {$vzid}");
				} else {
					Vps::getLogger()->info('appears to have cleanly shutdown');
					$stopped = true;
				}
			}
		}
		if ($stopped === false) {
			Vps::getLogger()->info('Sending Hardware Power-Off');
			Vps::getLogger()->write(Vps::runCommand("virsh destroy {$vzid};"));
		}
		Xinetd::remove($vzid);
		Xinetd::restart();
		Vps::getLogger()->unIndent();
	}

	public static function destroyVps($vzid) {
		if (Vps::isVpsRunning($vzid)) {
			Vps::getLogger()->write("VPS is running please stop first.\n");
		} else {
			Vps::getLogger()->write(Vps::runCommand("virsh managedsave-remove {$vzid}"));
			Vps::getLogger()->write(Vps::runCommand("virsh undefine {$vzid}"));
			self::removeStorage($vzid);
			Dhcpd::remove($vzid);
		}
	}

	public static function installTemplate($vzid, $template, $password, $device, $pool, $hd, $kpartxOpts) {
		Vps::getLogger()->info('Installing OS Template');
		return $pool == 'zfs' ? self::installTemplateV2($vzid, $template, $password, $device, $hd, $kpartxOpts) : self::installTemplateV1($vzid, $template, $password, $device, $hd, $kpartxOpts);
	}

	public static function setupRouting($vzid, $ip, $pool, $useAll, $id) {
		Vps::getLogger()->info('Setting up Routing');
		if ($useAll == false) {
			self::runBuildEbtables();
		}
		$base = Vps::$base;
		Vps::getLogger()->write(Vps::runCommand("{$base}/tclimit {$ip};"));
		self::blockSmtp($vzid, $id);
		if ($pool != 'zfs' && $useAll == false) {
			Vps::getLogger()->write(Vps::runCommand("/admin/kvmenable ebflush;"));
			Vps::getLogger()->write(Vps::runCommand("{$base}/buildebtablesrules | sh;"));
		}
	}

	public static function blockSmtp($vzid, $id) {
		Vps::getLogger()->write(Vps::runCommand("/admin/kvmenable blocksmtp {$vzid}"));
	}

	public static function installTemplateV2($vzid, $template, $password, $device, $hd, $kpartxOpts) {
		// kvmv2
		$base = Vps::$base;
		$downloadedTemplate = substr($template, 0, 7) == 'http://' || substr($template, 0, 8) == 'https://' || substr($template, 0, 6) == 'ftp://';
		if ($downloadedTemplate == true) {
			Vps::getLogger()->info("Downloading {$template} Image");
			Vps::getLogger()->write(Vps::runCommand("{$base}/vps_get_image.sh \"{$template} zfs\""));
			$template = 'image';
		}
		if (!file_exists('/vz/templates/'.$template.'.qcow2') && $template != 'empty') {
			Vps::getLogger()->info("There must have been a problem, the image does not exist");
			return false;
		} else {
			Vps::getLogger()->info("Copy {$template}.qcow2 Image");
			if ($hd == 'all') {
				$hd = intval(trim(Vps::runCommand("zfs list vz -o available -H -p"))) / (1024 * 1024);
				if ($hd > 2000000)
					$hd = 2000000;
			}
			if (stripos($template, 'freebsd') !== false) {
				Vps::getLogger()->write(Vps::runCommand("cp -f /vz/templates/{$template}.qcow2 {$device};"));
				Vps::getLogger()->write(Vps::runCommand("qemu-img resize {$device} \"{$hd}\"M;"));
			} else {
				Vps::getLogger()->write(Vps::runCommand("qemu-img create -f qcow2 -o preallocation=metadata {$device} 25G;"));
				Vps::getLogger()->write(Vps::runCommand("qemu-img resize {$device} \"{$hd}\"M;"));
				if ($template != 'empty') {
					Vps::getLogger()->debug('Listing Partitions in Template');
					$part = trim(Vps::runCommand("virt-list-partitions /vz/templates/{$template}.qcow2|tail -n 1;"));
					$backuppart = trim(Vps::runCommand("virt-list-partitions /vz/templates/{$template}.qcow2|head -n 1;"));
					Vps::getLogger()->debug('List Partitions got partition '.$part.' and backup partition '.$backuppart);
					Vps::getLogger()->debug('Copying and Resizing Template');
					Vps::getLogger()->write(Vps::runCommand("virt-resize --expand {$part} /vz/templates/{$template}.qcow2 {$device} || virt-resize --expand {$backuppart} /vz/templates/{$template}.qcow2 {$device} || cp -fv /vz/templates/{$template}.qcow2 {$device}"));
				}
			}
			if ($downloadedTemplate === true) {
				Vps::getLogger()->info("Removing Downloaded Image");
				Vps::getLogger()->write(Vps::runCommand("rm -f /vz/templates/{$template}.qcow2"));
			}
			Vps::getLogger()->write(Vps::runCommand("virsh detach-disk {$vzid} vda --persistent;"));
			Vps::getLogger()->write(Vps::runCommand("virsh attach-disk {$vzid} /vz/{$vzid}/os.qcow2 vda --targetbus virtio --driver qemu --subdriver qcow2 --type disk --sourcetype file --persistent;"));
			Vps::getLogger()->write(Vps::runCommand("virsh dumpxml {$vzid} > {$vzid}.xml"));
			Vps::getLogger()->write(Vps::runCommand("sed s#\"type='qcow2'/\"#\"type='qcow2' cache='writeback' discard='unmap'/\"#g -i {$vzid}.xml"));
			Vps::getLogger()->write(Vps::runCommand("virsh define {$vzid}.xml"));
			Vps::getLogger()->write(Vps::runCommand("rm -f {$vzid}.xml"));
		}
		return true;
	}

	public static function installTemplateV1($vzid, $template, $password, $device, $hd, $kpartxOpts) {
		$adjust_partitions = 1;
		$base = Vps::$base;
		$softraid = trim(Vps::runCommand("grep -l -v idle /sys/block/md*/md/sync_action 2>/dev/null"));
		$softraid = '' == $softraid ? [] : explode("\n", $softraid);
		if (count($softraid) > 0)
			foreach ($softraid as $softfile)
				file_put_contents($softfile, 'idle');
		if (substr($template, 0, 7) == 'http://' || substr($template, 0, 8) == 'https://' || substr($template, 0, 6) == 'ftp://') {
			// image from url
			$adjust_partitions = 0;
			Vps::getLogger()->info("Downloading {$template} Image");
			Vps::getLogger()->write(Vps::runCommand("{$base}/vps_get_image.sh \"{$template}\""));
			if (!file_exists('/image_storage/image.img')) {
				Vps::getLogger()->info("There must have been a problem, the image does not exist");
				if (count($softraid) > 0)
					foreach ($softraid as $softfile)
						file_put_contents($softfile, 'check');
				return false;
			} else {
				self::installImage('/image_storage/image.img', $device);
				Vps::getLogger()->info("Removing Downloaded Image");
			}
			Vps::getLogger()->write(Vps::runCommand("umount /image_storage;"));
			Vps::getLogger()->write(Vps::runCommand("virsh vol-delete --pool vz image_storage;"));
			Vps::getLogger()->write(Vps::runCommand("rmdir /image_storage;"));
		} elseif ($template == 'empty') {
			// kvmv1 install empty image
			$adjust_partitions = 0;
		} else {
			// kvmv1 install
			$found = 0;
			foreach (['/vz/templates/', '/templates/', '/'] as $prefix) {
				$source = $prefix.$template.'.img.gz';
				if ($found == 0 && file_exists($source)) {
					$found = 1;
					self::installGzImage($source, $device);
				}
			}
			foreach (['/vz/templates/', '/templates/', '/', '/dev/vz/'] as $prefix) {
				foreach (['.img', ''] as $suffix) {
					$source = $prefix.$template.$suffix;
					if ($found == 0 && file_exists($source)) {
						$found = 1;
						self::installImage($source, $device);
					}
				}
			}
			if ($found == 0) {
				Vps::getLogger()->info("Template does not exist");
				if (count($softraid) > 0)
					foreach ($softraid as $softfile)
						file_put_contents($softfile, 'check');
				return false;
			}
		}
		if ($adjust_partitions == 1) {
			$sects = trim(Vps::runCommand("fdisk -l -u {$device}  | grep sectors$ | sed s#\"^.* \([0-9]*\) sectors$\"#\"\\1\"#g"));
			$t = trim(Vps::runCommand("fdisk -l -u {$device} | sed s#\"\*\"#\"\"#g | grep \"^{$device}\" | tail -n 1"));
			$p = trim(Vps::runCommand("echo {$t} | awk '{ print $1 }'"));
			$fs = trim(Vps::runCommand("echo {$t} | awk '{ print $5 }'"));
			if (trim(Vps::runCommand("echo \"{$fs}\" | grep \"[A-Z]\"")) != '') {
				$fs = trim(Vps::runCommand("echo {$t} | awk '{ print $6 }'"));
			}
			$pn = trim(Vps::runCommand("echo \"{$p}\" | sed s#\"{$device}[p]*\"#\"\"#g"));
			$pt = $pn > 4 ? 'l' : 'p';
			$start = trim(Vps::runCommand("echo {$t} | awk '{ print $2 }'"));
			if ($fs == 83) {
				Vps::getLogger()->info("Resizing Last Partition To Use All Free Space (Sect {$sects} P {$p} FS {$fs} PN {$pn} PT {$pt} Start {$start}");
				Vps::getLogger()->write(Vps::runCommand("echo -e \"d\n{$pn}\nn\n{$pt}\n{$pn}\n{$start}\n\n\nw\nprint\nq\n\" | fdisk -u {$device}"));
				Vps::getLogger()->write(Vps::runCommand("kpartx {$kpartxOpts} -av {$device}"));
				$pname = trim(Vps::runCommand("ls /dev/mapper/vz-\"{$vzid}\"p{$pn} /dev/mapper/vz-{$vzid}{$pn} /dev/mapper/\"{$vzid}\"p{$pn} /dev/mapper/{$vzid}{$pn} 2>/dev/null | cut -d/ -f4 | sed s#\"{$pn}$\"#\"\"#g"));
				Vps::getLogger()->write(Vps::runCommand("e2fsck -p -f /dev/mapper/{$pname}{$pn}"));
				$resizefs = trim(Vps::runCommand("which resize4fs 2>/dev/null")) != '' ? 'resize4fs' : 'resize2fs';
				Vps::getLogger()->write(Vps::runCommand("$resizefs -p /dev/mapper/{$pname}{$pn}"));
				@mkdir('/vz/mounts/'.$vzid.$pn, 0777, true);
				Vps::getLogger()->write(Vps::runCommand("mount /dev/mapper/{$pname}{$pn} /vz/mounts/{$vzid}{$pn};"));
				$password = escapeshellarg($password);
				Vps::getLogger()->write(Vps::runCommand("echo root:{$password} | chroot /vz/mounts/{$vzid}{$pn} chpasswd || php {$base}/vps_kvm_password_manual.php {$password} \"/vz/mounts/{$vzid}{$pn}\""));
				if (file_exists('/vz/mounts/'.$vzid.$pn.'/home/kvm')) {
					Vps::getLogger()->write(Vps::runCommand("echo kvm:{$password} | chroot /vz/mounts/{$vzid}{$pn} chpasswd"));
				}
				Vps::getLogger()->write(Vps::runCommand("umount /dev/mapper/{$pname}{$pn}"));
				Vps::getLogger()->write(Vps::runCommand("kpartx {$kpartxOpts} -d {$device}"));
			} else {
				Vps::getLogger()->info("Skipping Resizing Last Partition FS is not 83. Space (Sect {$sects} P {$p} FS {$fs} PN {$pn} PT {$pt} Start {$start}");
			}
		}
		if (count($softraid) > 0)
			foreach ($softraid as $softfile)
				file_put_contents($softfile, 'check');
		return true;
	}

	public static function installGzImage($source, $device) {
		Vps::getLogger()->info("Copying {$source} Image");
		$tsize = trim(Vps::runCommand("stat -c%s \"{$source}\""));
		Vps::getLogger()->write(Vps::runCommand("gzip -dc \"/{$source}\"  | dd of={$device} 2>&1"));
		return true;
	}

	public static function installImage($source, $device) {
		Vps::getLogger()->info("Copying Image");
		$tsize = trim(Vps::runCommand("stat -c%s \"{$source}\""));
		Vps::getLogger()->write(Vps::runCommand("dd \"if={$source}\" \"of={$device}\" 2>&1"));
		Vps::getLogger()->write(Vps::runCommand("rm -f dd.progress;"));
		return true;
	}
}
