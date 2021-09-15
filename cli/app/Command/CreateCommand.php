<?php
namespace App\Command;

use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;
use CLIFramework\Component\Progress\ProgressBar;
use CLIFramework\Component\Table\Table;
use CLIFramework\Component\Table\TableStyle;
use CLIFramework\Component\Table\CompactTableStyle;
use CLIFramework\Component\Table\CellAttribute;
use CLIFramework\Component\Table\CurrencyFormatCell;
use CLIFramework\Component\Table\MarkdownTableStyle;

class CreateCommand extends Command {
	public $virts = [
		'kvm' => '/usr/bin/virsh',
		'virtuozzo' => '/usr/bin/prlctl',
		'openvz' => '/usr/bin/vzctl',
		'lxc' => '/usr/bin/lxc',
	];

	public $virtValidations = [
		'kvm-ok',
		'lscpu',
		'/proc/cpuinfo' => 'egrep "svm|vmx" /proc/cpuinfo',
		'virt-host-validate'
	];

	public function brief() {
		return "Creates a Virtual Machine.";
	}

	public function options($opts) {
        $opts->add('h|hostname:', 'Hostname for the vps')
        	->isa('string');
        $opts->add('m|mac:', 'MAC Address')
        	->isa('string');
        $opts->add('slices:', 'Number of Slices for the vps')
        	->isa('number');
        $opts->add('slice-hd:', 'Amount of HD Space in GB per Slice')
        	->isa('number')
        	->defaultValue(25);
        $opts->add('slice-ram:', 'Amount of RAM in MB per Slice')
        	->isa('number')
        	->defaultValue(1024);
        $opts->add('additional-hd:', 'Amount of additional HD space beyond the slice amount in GB')
        	->isa('number')
        	->defaultValue(0);
        $opts->add('ip:', 'IP Address for the vps')
        	->isa('Ip');
        $opts->add('id:', 'Order ID # for the vps')
        	->isa('number');
        $opts->add('vzid:', 'VZID')
        	->isa('string');
        $opts->add('ips:', 'Additional IPs')
        	->isa('string');
        $opts->add('e|email:', 'Email Address of the VPS owner')
        	->isa('string');
        $opts->add('custid:', 'Customer ID #')
        	->isa('number');
        $opts->add('clientip:', 'Client IP')
        	->isa('ip');
        $opts->add('p|password:', 'Root Password')
        	->isa('string');
	}

	public function execute() {
		/**
		* @var {\GetOptionKit\OptionResult|GetOptionKit\OptionCollection}
		*/
		$opts = $this->getOptions();
		print_r($opts);
		echo "Slice HD:".$opts->sliceHd."\n";
		print_r(array_keys($opts->keys));
		exit;
		$base = '/root/cpaneldirect';
		$ram = $slices * $settings['slice_ram'] * 1024;
		$hd = (($settings['slice_hd'] * $slices) + $settings['additional_hd']) * 1024;
		$cpu = $slices;
        $softraid = '';
        $error = 0;
        $adjust_partitions = 1;
        $PREPATH='';
        $this->progress(1);
        if ($module == 'quickservers') {
			$size = 'all';
			preg_match('/^MemTotal:\s+(\d+)\skB/', file_get_contents('/proc/meminfo'), $matches);
			$memory = floor(intval($matches[1]) / 100 * 70);
			preg_match('/CPU\(s\):\s+(\d+)/', `lscpu`, $matches);
			$cpu = $matches[1];
        } else {
            $size = $hd;
            $memory = $ram;
        }
        $kpartsOpts = preg_match('/sync/', `kpartx 2>&1`) ? '-s' : '';
        $extraips = $ips;
        $ip = array_shift($extraips);
        $maxCpu = $cpu > 8 ? $cpu : 8;
    	$maxMemory = $memory > 16384000 ? $memory : 16384000;
    	if (file_exists('/etc/redhat-release') && intval(trim(`cat /etc/redhat-release |sed s#"^[^0-9]* "#""#g|cut -c1`)) <= 6) {
			if (floatval(trim(`e2fsck -V 2>&1 |head -n 1 | cut -d" " -f2 | cut -d"." -f1-2`)) <= 1.41) {
				if (!file_exists('/opt/e2fsprogs/sbin/e2fsck')) {
					echo `/admin/ports/install e2fsprogs;`;
				}
			}
    	}
		$this->progress(3);
		$device = '/dev/vz/'.$vzid;
		$pool = xml2array(file_get_contents(`virsh pool-dumpxml vz 2>/dev/null`))['pool_attr']['type'];
		if ($pool == '') {
			echo `{$base}/create_libvirt_storage_pools.sh`;
			$pool = xml2array(file_get_contents(`virsh pool-dumpxml vz 2>/dev/null`))['pool_attr']['type'];
		}
		if ($pool == 'zfs') {
			mkdir('/vz/'.$vzid, 0777, true);
			echo `zfs create vz/{$vzid}`;
			$device='/vz/'.$vzid.'/os.qcow2';
			while (!file_exists('/vz/'.$vzid)) {
				sleep(1);
			}
			//virsh vol-create-as --pool vz --name {$vzid}/os.qcow2 --capacity "$size"M --format qcow2 --prealloc-metadata
			//sleep 5s;
			//device="$(virsh vol-list vz --details|grep " {$vzid}[/ ]"|awk '{ print $2 }')"
		} else {
			echo `{$base}/vps_kvm_lvmcreate.sh {$vzid} {$size}`;
			// exit here on failed exit status
		}
		$this->progress(1);
		echo "{$pool} pool device {$device} created\n";
		passthru('/usr/bin/virsh dominfo '.$vzid.' >/dev/null 2>&1', $return);
		if ($return > 0) {
			echo `/usr/bin/virsh destroy {$vzid}`;
			echo `cp {$vzid}.xml {$vzid}.xml.backup`;
			echo `/usr/bin/virsh undefine {$vzid}`;
			echo `mv -f {$vzid}.xml.backup {$vzid}.xml`;
		} else {
			echo "Generating XML Config\n";
			if ($pool != 'zfs') {
				echo `grep -v -e uuid -e filterref -e "<parameter name='IP'" {$base}/windows.xml | sed s#"windows"#"{$vzid}"#g > {$vzid}.xml`;
			} else {
				echo `grep -v -e uuid {$base}/windows.xml | sed -e s#"windows"#"{$vzid}"#g -e s#"/dev/vz/{$vzid}"#"$device"#g > {$vzid}.xml`;
			}
			echo "Defining Config As VPS\n";
			if (!file_exists('/usr/libexec/qemu-kvm') && file_exists('/usr/bin/kvm')) {
				echo `sed s#"/usr/libexec/qemu-kvm"#"/usr/bin/kvm"#g -i {$vzid}.xml`;
			}
		}
		if ($module == 'quickservers') {
			echo `sed -e s#"^.*<parameter name='IP.*$"#""#g -e  s#"^.*filterref.*$"#""#g -i {$vzid}.xml`;
		} else {
			$repl = "<parameter name='IP' value='$ip'/>";
			if (count($extraips) > 0) {
				foreach ($extraips as $i) {
					$repl = "{$repl}\n        <parameter name='IP' value='{$i}'/>";
				}
			}
			echo `sed s#"<parameter name='IP' value.*/>"#"$repl"#g -i {$vzid}.xml;`;
		}
		$id = str_replace(['qs', 'windows', 'linux', 'vps'], ['', '', '', ''], $vzid);
		if ($id == $vzid) {
			$mac = $this->convert_id_to_mac($id, $module);
			echo `sed s#"<mac address='.*'"#"<mac address='$mac'"#g -i {$vzid}.xml`;
		} else {
			echo `sed s#"^.*<mac address.*$"#""#g -i {$vzid}.xml`;
		}
		echo `sed s#"<\(vcpu.*\)>.*</vcpu>"#"<vcpu placement='static' current='$vcpu'>$max_cpu</vcpu>"#g -i {$vzid}.xml;`;
		echo `sed s#"<memory.*memory>"#"<memory unit='KiB'>$memory</memory>"#g -i {$vzid}.xml;`;
		echo `sed s#"<currentMemory.*currentMemory>"#"<currentMemory unit='KiB'>$memory</currentMemory>"#g -i {$vzid}.xml;`;
		if (trim(`grep -e "flags.*ept" -e "flags.*npt" /proc/cpuinfo`) != '') {
			echo `sed s#"<features>"#"<features>\n    <hap/>"#g -i {$vzid}.xml;`;
		}
		if (trim(`date "+%Z"`) == 'PDT') {
			echo `sed s#"America/New_York"#"America/Los_Angeles"#g -i {$vzid}.xml;`;
		}
		if (file_exists('/etc/lsb-release')) {
			if (substr($vps_os, 0, 7) == 'windows') {
				echo `sed -e s#"</features>"#"  <hyperv>\n      <relaxed state='on'/>\n      <vapic state='on'/>\n      <spinlocks state='on' retries='8191'/>\n    </hyperv>\n  </features>"#g -i {$vzid}.xml;`;
				echo `sed -e s#"<clock offset='timezone' timezone='\([^']*\)'/>"#"<clock offset='timezone' timezone='\1'>\n    <timer name='hypervclock' present='yes'/>\n  </clock>"#g -i {$vzid}.xml;`;
			}
			echo `sed s#"\(<controller type='scsi' index='0'.*\)>"#"\1 model='virtio-scsi'>\n      <driver queues='$vcpu'/>"#g -i  {$vzid}.xml;`;
		}
		echo `/usr/bin/virsh define {$vzid}.xml;`;
		//echo `/usr/bin/virsh setmaxmem {$vzid} $memory;`;
		//echo `/usr/bin/virsh setmem {$vzid} $memory;`;
		//echo `/usr/bin/virsh setvcpus {$vzid} $vcpu;`;
		$mac = xml2array(`/usr/bin/virsh dumpxml {$vzid};`)['domain']['devices']['interface']['mac_attr']['address'];
		$this->setupDhcpd($vzid, $ip, $mac);
		$this->progress(15);
		echo "Custid is {$custid}\n";
		if ($custid == 565600) {
			if (!file_exists('/vz/templates/template.281311.qcow2')) {
				echo `wget -O /vz/templates/template.281311.qcow2 http://kvmtemplates.is.cc/cl/template.281311.qcow2;`;
			}
			$vps_os = 'template.281311';
		}
		if ($pool == 'zfs') {
			// kvmv2
			if (file_exists('/vz/templates/'.$vps_os.'.qcow2') || $vps_os == 'empty') {
				echo "Copy {$vps_os}.qcow2 Image\n";
				if ($size == 'all') {
					$size = intval(`zfs list vz -o available -H -p`) / (1024 * 1024);
					if ($size > 2000000)
						$size = 2000000;
				}
				if (stripos($vps_os, 'freebsd') !== false) {
					echo `cp -f /vz/templates/{$vps_os}.qcow2 {$device};`;
					$this->progress(60);
					echo `qemu-img resize {$device} "{$size}"M;`;
				} else {
					echo `qemu-img create -f qcow2 -o preallocation=metadata {$device} 25G;`;
					$this->progress(40);
					echo `qemu-img resize {$device} "{$size}"M;`;
					$this->progress(70);
					if ($vps_os != 'empty') {
						$part = `virt-list-partitions /vz/templates/{$vps_os}.qcow2|tail -n 1;`;
						$backuppart = `virt-list-partitions /vz/templates/{$vps_os}.qcow2|head -n 1;`;
						if ($vps_os != 'template.281311') {
							echo `virt-resize --expand {$part} /vz/templates/{$vps_os}.qcow2 {$device} || virt-resize --expand {$backuppart} /vz/templates/{$vps_os}.qcow2 {$device} ;`;
						} else {
							echo `cp -fv /vz/templates/{$vps_os}.qcow2 {$device};`;
						}
					}
				}
				$this->progress(90);
				echo `virsh detach-disk {$vzid} vda --persistent;`;
				echo `virsh attach-disk {$vzid} /vz/{$vzid}/os.qcow2 vda --targetbus virtio --driver qemu --subdriver qcow2 --type disk --sourcetype file --persistent;`;
				echo `virsh dumpxml {$vzid} > vps.xml`;
				echo `sed s#"type='qcow2'/"#"type='qcow2' cache='writeback' discard='unmap'/"#g -i vps.xml`;
				echo `virsh define vps.xml`;
				echo `rm -f vps.xml`;
				echo `virt-customize -d {$vzid} --root-password password:{$rootpass} --hostname "{$vzid}";`;
				$adjust_partitions = 0;
			}
		} elseif (substr($vps_os, 0, 7) == 'http://' || substr($vps_os, 0, 8) == 'https://' || substr($vps_os, 0, 6) == 'ftp://') {
			// image from url
			$adjust_partitions = 0;
			echo "Downloading {$vps_os} Image\n";
			echo `{$base}/vps_get_image.sh "{$vps_os}"`;
			if (!file_exists('/image_storage/image.raw.img')) {
				echo "There must have been a problem, the image does not exist\n";
				$error++;
			} else {
				$this->install_image('/image_storage/image.raw.img', $device);
				echo "Removing Downloaded Image\n";
				echo `umount /image_storage;`;
				echo `virsh vol-delete --pool vz image_storage;`;
				echo `rmdir /image_storage;`;
			}
		} elseif ($vps_os == 'empty') {
			// kvmv1 install empty image
			$adjust_partitions = 0;
		} else {
			// kvmv1 install
			$found = 0;
			foreach (['/vz/templates/', '/templates/', '/'] as $prefix) {
				$source = $prefix.$vps_os.'.img.gz';
				if ($found == 0 && file_exists($source)) {
					$found = 1;
					$this->install_gz_image($source, $device);
				}
			}
			foreach (['/vz/templates/', '/templates/', '/', '/dev/vz/'] as $prefix) {
				foreach (['.img', ''] as $suffix) {
					$source = $prefix.$vps_os.$suffix;
					if ($found == 0 && file_exists($source)) {
						$found = 1;
						$this->install_image($source, $device);
					}
				}
			}
			if ($found == 0) {
				echo "Template does not exist\n";
				$error++;
			}
		}
		if (count($softraid) > 0) {
			foreach ($softraid as $softfile) {
				file_put_contents($softfile, 'check');
			}
		}
		echo "Errors: {$error}  Adjust Partitions: {$adjust_partitions}\n";
		if ($error == 0) {
			if ($adjust_partitions == 1) {
				$this->progress('resizing');
				$sects = trim(`fdisk -l -u $device  | grep sectors$ | sed s#"^.* \([0-9]*\) sectors$"#"\1"#g`);
				$t = trim(`fdisk -l -u $device | sed s#"\*"#""#g | grep "^$device" | tail -n 1`);
				$p = trim(`echo $t | awk '{ print $1 }'`);
				$fs = trim(`echo $t | awk '{ print $5 }'`);
				if (trim(`echo "$fs" | grep "[A-Z]")`) != '') {
					$fs = trim(`echo $t | awk '{ print $6 }'`);
				}
				$pn = trim(`echo "$p" | sed s#"$device[p]*"#""#g`);
				$pt = $pn > 4 ? 'l' : 'p';
				$start = trim(`echo $t | awk '{ print $2 }'`);
				if ($fs == 83) {
					echo "Resizing Last Partition To Use All Free Space (Sect $sects P {$p} FS {$fs} PN {$pn} PT {$pt} Start {$start}\n";
					echo `echo -e "d\n{$pn}\nn\n{$pt}\n{$pn}\n{$start}\n\n\nw\nprint\nq\n" | fdisk -u {$device}`;
					echo `kpartx $kpartxopts -av {$device}`;
					$pname = trim(`ls /dev/mapper/vz-"{$vzid}"p{$pn} /dev/mapper/vz-{$vzid}{$pn} /dev/mapper/"{$vzid}"p{$pn} /dev/mapper/{$vzid}{$pn} 2>/dev/null | cut -d/ -f4 | sed s#"{$pn}$"#""#g`);
					echo `e2fsck -p -f /dev/mapper/{$pname}{$pn}`;
					$resizefs = trim(`which resize4fs 2>/dev/null`) != '' ? 'resize4fs' : 'resize2fs';
					echo `$resizefs -p /dev/mapper/{$pname}{$pn}`;
					mkdir('/vz/mounts/'.$vzid.$pn, 0777, true);
					echo `mount /dev/mapper/{$pname}{$pn} /vz/mounts/{$vzid}{$pn};`;
					echo `echo root:{$rootpass} | chroot /vz/mounts/{$vzid}{$pn} chpasswd || php {$base}/vps_kvm_password_manual.php {$rootpass} "/vz/mounts/{$vzid}{$pn}"`;
					if (file_exists('/vz/mounts/'.$vzid.$pn.'/home/kvm')) {
						echo `echo kvm:{$rootpass} | chroot /vz/mounts/{$vzid}{$pn} chpasswd`;
					}
					echo `umount /dev/mapper/{$pname}{$pn}`;
					echo `kpartx $kpartxopts -d {$device}`;
				} else {
					echo "Skipping Resizing Last Partition FS is not 83. Space (Sect $sects P {$p} FS {$fs} PN {$pn} PT {$pt} Start {$start}\n";
				}
			}
			touch('/tmp/_securexinetd');
			echo `/usr/bin/virsh autostart {$vzid};`;
			$this->progress('starting');
			echo `/usr/bin/virsh start {$vzid};`;
			if ($pool != 'zfs') {
				echo `bash {$base}/run_buildebtables.sh;`;
			}
			if ($module == 'vps') {
				if (!file_exists('/cgroup/blkio/libvirt/qemu')) {
					echo "CGroups not detected\n";
				} else {
					$cpushares = $slices * 512;
					$ioweight = 400 + (37 * $slices);
					echo `virsh schedinfo {$vzid} --set cpu_shares={$cpushares} --current;`;
					echo `virsh schedinfo {$vzid} --set cpu_shares={$cpushares} --config;`;
					echo `virsh blkiotune {$vzid} --weight {$ioweight} --current;`;
					echo `virsh blkiotune {$vzid} --weight {$ioweight} --config;`;
				}
			}
			echo `{$base}/tclimit {$ip};`;
			if ($clientip != '') {
				$clientip = escapeshellarg($clientip);
				echo `{$base}/vps_kvm_setup_vnc.sh {$vzid} {$clientip};`;
			}
			echo `{$base}/vps_refresh_vnc.sh {$vzid};`;
			$vnc = trim(`virsh vncdisplay {$vzid} | cut -d: -f2 | head -n 1`);
			if ($vnc == '') {
				sleep(2);
				$vnc = trim(`virsh vncdisplay {$vzid} | cut -d: -f2 | head -n 1`);
				if ($vnc == '') {
					sleep(2);
					$vnc = trim(`virsh dumpxml {$vzid} |grep -i "graphics type='vnc'" | cut -d\' -f4`);
				} else {
					$vnc += 5900;
				}
			} else {
				$vnc += 5900;
			}
			$vnc -= 5900;
			echo `{$base}/vps_kvm_screenshot.sh "{$vnc}" "$url?action=screenshot&name={$vzid}";`;
			sleep(1);
			echo `{$base}/vps_kvm_screenshot.sh "{$vnc}" "$url?action=screenshot&name={$vzid}";`;
			sleep(1);
			echo `{$base}/vps_kvm_screenshot.sh "{$vnc}" "$url?action=screenshot&name={$vzid}";`;
			$vnc += 5900;
			echo `/admin/kvmenable blocksmtp {$vzid};`;
			echo `rm -f /tmp/_securexinetd;`;
			if ($module == 'vps') {
				echo `/admin/kvmenable ebflush;`;
				echo `{$base}/buildebtablesrules | sh;`;
			}
			echo `service xinetd restart`;
		}
		$this->progress(100);
	}

    public function getInstalledVirts() {
		$found = [];
		foreach ($virts as $virt => $virtBin) {
			if (file_exists($virtBin)) {
				$found[] = $virt;
			}
		}
		return $found;
    }

    public function progress($progress) {

    }

    public function setupDhcpd($vzid, $ip, $mac) {
		$dhcpvps = file_exists('/etc/dhcp/dhcpd.vps') ? '/etc/dhcp/dhcpd.vps' : '/etc/dhcpd.vps';
		$dhcpservice = file_exists('/etc/apt') ? 'isc-dhcp-server' : 'dhcpd';
		echo `/bin/cp -f {$dhcpvps} {$dhcpvps}.backup;`;
    	echo `grep -v -e "host {$vzid} " -e "fixed-address {$ip};" {$dhcpvps}.backup > {$dhcpvps}`;
    	echo `echo "host {$vzid} { hardware ethernet {$mac}; fixed-address {$ip}; }" >> {$dhcpvps}`;
    	echo `rm -f {$dhcpvps}.backup;`;
    	echo `systemctl restart {$dhcpservice} 2>/dev/null || service {$dhcpservice} restart 2>/dev/null || /etc/init.d/{$dhcpservice} restart 2>/dev/null`;
    }

    public function install_gz_image($source, $device) {
    	echo "Copying {$source} Image\n";
    	$tsize = trim(`stat -c%s "{$source}"`);
    	echo `gzip -dc "/{$source}"  | dd of={$device} 2>&1`;
    	/*
	gzip -dc "/$source"  | dd of=$device 2>&1 &
	pid=$!
	echo "Got DD PID $pid";
	sleep 2s;
	if [ "$(pidof gzip)" != "" ]; then
		pid="$(pidof gzip)";
		echo "Tried again, got gzip PID $pid";
	fi;
	if [ "$(echo "$pid" | grep " ")" != "" ]; then
		pid=$(pgrep -f 'gzip -dc');
		echo "Didn't like gzip pid (had a space?), going with gzip PID $pid";
	fi;
	tsize="$(stat -L /proc/$pid/fd/3 -c "%s")";
	echo "Got Total Size $tsize";
	if [ -z $tsize ]; then
		tsize="$(stat -c%s "/$source")";
		echo "Falling back to filesize check, got size $tsize";
	fi;
	while [ -d /proc/$pid ]; do
		copied=$(awk '/pos:/ { print $2 }' /proc/$pid/fdinfo/3);
		completed="$(echo "$copied/$tsize*100" |bc -l | cut -d\. -f1)";
		iprogress $completed &*/
//		if [ "$(ls /sys/block/md*/md/sync_action 2>/dev/null)" != "" ] && [ "$(grep -v idle /sys/block/md*/md/sync_action 2>/dev/null)" != "" ]; then
//			export softraid="$(grep -l -v idle /sys/block/md*/md/sync_action 2>/dev/null)";
/*			for softfile in $softraid; do
				echo idle > $softfile;
			done;
		fi;
		echo "$completed%";
		sleep 10s
	done
	*/
	}

	public function install_image($source, $device) {
		echo "Copying Image\n";
		$tsize = trim(`stat -c%s "{$source}"`);
		echo `dd "if={$source}" "of={$device}" 2>&1`;
		/*
	dd "if=$source" "of=$device" >dd.progress 2>&1 &
	pid=$!
	while [ -d /proc/$pid ]; do
		sleep 9s;
		kill -SIGUSR1 $pid;
		sleep 1s;
		if [ -d /proc/$pid ]; then
			copied=$(tail -n 1 dd.progress | cut -d" " -f1);
			completed="$(echo "$copied/$tsize*100" |bc -l | cut -d\. -f1)";
			iprogress $completed &
			*/
			//if [ "$(ls /sys/block/md*/md/sync_action 2>/dev/null)" != "" ] && [ "$(grep -v idle /sys/block/md*/md/sync_action 2>/dev/null)" != "" ]; then
//				export softraid="$(grep -l -v idle /sys/block/md*/md/sync_action 2>/dev/null)";
/*				for softfile in $softraid; do
					echo idle > $softfile;
				done;
			fi;
			echo "$completed%";
		fi;
	done;
	*/
		echo `rm -f dd.progress;`;
	}

	public function convert_id_to_mac($id, $module) {
		$prefix = $module == 'quickservers' ? '00:0C:29' : '00:16:3E';
		$suffix = strtoupper(sprintf("%06s", dechex($id)));
		$mac = $prefix.':'.substr($suffix, 0, 2).':'.substr($suffix, 2, 2).':'.substr($suffix, 4, 2);
		return $mac;
	}

}
