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

	public function execute() {
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
/*
if [ -e /etc/redhat-release ] && [ $(cat /etc/redhat-release |sed s#"^[^0-9]* "#""#g|cut -c1) -le 6 ]; then
	if [ $(echo "$(e2fsck -V 2>&1 |head -n 1 | cut -d" " -f2 | cut -d"." -f1-2) * 100" | bc | cut -d"." -f1) -le 141 ]; then
		if [ ! -e /opt/e2fsprogs/sbin/e2fsck ]; then
			pushd $PWD;
			cd /admin/ports
			./install e2fsprogs
			popd;
		fi;
		export PREPATH="/opt/e2fsprogs/sbin:";
		export PATH="$PREPATH$PATH";
	fi;
fi;
*/
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
/*
cd /etc/libvirt/qemu
if /usr/bin/virsh dominfo {$vzid} >/dev/null 2>&1; then
	/usr/bin/virsh destroy {$vzid}
	cp {$vzid}.xml {$vzid}.xml.backup
	/usr/bin/virsh undefine {$vzid}
	mv -f {$vzid}.xml.backup {$vzid}.xml
else
	echo "Generating XML Config"
	if [ "$pool" != "zfs" ]; then
		grep -v -e uuid -e filterref -e "<parameter name='IP'" {$base}/windows.xml | sed s#"windows"#"{$vzid}"#g > {$vzid}.xml
	else
		grep -v -e uuid {$base}/windows.xml | sed -e s#"windows"#"{$vzid}"#g -e s#"/dev/vz/{$vzid}"#"$device"#g > {$vzid}.xml
	fi
	echo "Defining Config As VPS"
	if [ ! -e /usr/libexec/qemu-kvm ] && [ -e /usr/bin/kvm ]; then
	  sed s#"/usr/libexec/qemu-kvm"#"/usr/bin/kvm"#g -i {$vzid}.xml
	fi;
fi
*/
		if ($module == 'quickservers') {
/*
sed -e s#"^.*<parameter name='IP.*$"#""#g -e  s#"^.*filterref.*$"#""#g -i {$vzid}.xml
*/
		} else {
/*
	repl="<parameter name='IP' value='$ip'/>";
	if [ "$extraips" != "" ]; then
		for i in $extraips; do
			repl="$repl\n        <parameter name='IP' value='$i'/>";
		done
	fi
*/
//	sed s#"<parameter name='IP' value.*/>"#"$repl"#g -i {$vzid}.xml;
		}
		$id = str_replace(['qs', 'windows', 'linux', 'vps'], ['', '', '', ''], $vzid);
		if ($id == $vzid) {
			$mac = $this->convert_id_to_mac($id, $module);
/*
	sed s#"<mac address='.*'"#"<mac address='$mac'"#g -i {$vzid}.xml
*/
		} else {
/*
	sed s#"^.*<mac address.*$"#""#g -i {$vzid}.xml
*/
		}
/*
sed s#"<\(vcpu.*\)>.*</vcpu>"#"<vcpu placement='static' current='$vcpu'>$max_cpu</vcpu>"#g -i {$vzid}.xml;
sed s#"<memory.*memory>"#"<memory unit='KiB'>$memory</memory>"#g -i {$vzid}.xml;
sed s#"<currentMemory.*currentMemory>"#"<currentMemory unit='KiB'>$memory</currentMemory>"#g -i {$vzid}.xml;
*/
//sed s#"<parameter name='IP' value.*/>"#"$repl"#g -i {$vzid}.xml;
/*
if [ "$(grep -e "flags.*ept" -e "flags.*npt" /proc/cpuinfo)" != "" ]; then
	sed s#"<features>"#"<features>\n    <hap/>"#g -i {$vzid}.xml;
fi
if [ "$(date "+%Z")" = "PDT" ]; then
	sed s#"America/New_York"#"America/Los_Angeles"#g -i {$vzid}.xml;
fi
if [ -e /etc/lsb-release ]; then
	if [ "$(echo "{$vps_os}"|cut -c1-7)" = "windows" ]; then
		sed -e s#"</features>"#"  <hyperv>\n      <relaxed state='on'/>\n      <vapic state='on'/>\n      <spinlocks state='on' retries='8191'/>\n    </hyperv>\n  </features>"#g -i {$vzid}.xml;
		sed -e s#"<clock offset='timezone' timezone='\([^']*\)'/>"#"<clock offset='timezone' timezone='\1'>\n    <timer name='hypervclock' present='yes'/>\n  </clock>"#g -i {$vzid}.xml;
	fi;
	. /etc/lsb-release;
	if [ $(echo $DISTRIB_RELEASE|cut -d\. -f1) -ge 18 ]; then
		sed s#"\(<controller type='scsi' index='0'.*\)>"#"\1 model='virtio-scsi'>\n      <driver queues='$vcpu'/>"#g -i  {$vzid}.xml;
	fi;
fi;

*/
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
/*
	if [ "$adjust_partitions" = "1" ]; then
		iprogress resizing &
		sects="$(fdisk -l -u $device  | grep sectors$ | sed s#"^.* \([0-9]*\) sectors$"#"\1"#g)"
		t="$(fdisk -l -u $device | sed s#"\*"#""#g | grep "^$device" | tail -n 1)"
		p="$(echo $t | awk '{ print $1 }')"
		fs="$(echo $t | awk '{ print $5 }')"
		if [ "$(echo "$fs" | grep "[A-Z]")" != "" ]; then
			fs="$(echo $t | awk '{ print $6 }')"
		fi;
		pn="$(echo "$p" | sed s#"$device[p]*"#""#g)"
		if [ $pn -gt 4 ]; then
			pt=l
		else
			pt=p
		fi
		start="$(echo $t | awk '{ print $2 }')"
		if [ "$fs" = "83" ]; then
			echo "Resizing Last Partition To Use All Free Space (Sect $sects P $p FS $fs PN $pn PT $pt Start $start"
			echo -e "d\n$pn\nn\n$pt\n$pn\n$start\n\n\nw\nprint\nq\n" | fdisk -u $device
			kpartx $kpartxopts -av $device
			pname="$(ls /dev/mapper/vz-"{$vzid}"p$pn /dev/mapper/vz-{$vzid}$pn /dev/mapper/"{$vzid}"p$pn /dev/mapper/{$vzid}$pn 2>/dev/null | cut -d/ -f4 | sed s#"$pn$"#""#g)"
			e2fsck -p -f /dev/mapper/$pname$pn
			if [ -f "$(which resize4fs 2>/dev/null)" ]; then
				resizefs="resize4fs"
			else
				resizefs="resize2fs"
			fi
			$resizefs -p /dev/mapper/$pname$pn
			mkdir -p /vz/mounts/{$vzid}$pn
			mount /dev/mapper/$pname$pn /vz/mounts/{$vzid}$pn;
			PATH="$PREPATH/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin" \
			echo root:{$rootpass} | chroot /vz/mounts/{$vzid}$pn chpasswd || \
			php {$base}/vps_kvm_password_manual.php {$rootpass} "/vz/mounts/{$vzid}$pn"
			if [ -e /vz/mounts/{$vzid}$pn/home/kvm ]; then
				echo kvm:{$rootpass} | chroot /vz/mounts/{$vzid}$pn chpasswd
			fi;
			umount /dev/mapper/$pname$pn
			kpartx $kpartxopts -d $device
		else
			echo "Skipping Resizing Last Partition FS is not 83. Space (Sect $sects P $p FS $fs PN $pn PT $pt Start $start"
		fi
	fi
	touch /tmp/_securexinetd;
	/usr/bin/virsh autostart {$vzid};
	*/
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
	/*
	vnc="$((5900 + $(virsh vncdisplay {$vzid} | cut -d: -f2 | head -n 1)))";
	if [ "$vnc" == "" ]; then
		sleep 2s;
		vnc="$((5900 + $(virsh vncdisplay {$vzid} | cut -d: -f2 | head -n 1)))";
		if [ "$vnc" == "" ]; then
			sleep 2s;
			vnc="$(virsh dumpxml {$vzid} |grep -i "graphics type='vnc'" | cut -d\' -f4)";
		fi;
	fi;
	{$base}/vps_kvm_screenshot.sh "$(($vnc - 5900))" "$url?action=screenshot&name={$vzid}";
	sleep 1s;
	{$base}/vps_kvm_screenshot.sh "$(($vnc - 5900))" "$url?action=screenshot&name={$vzid}";
	sleep 1s;
	{$base}/vps_kvm_screenshot.sh "$(($vnc - 5900))" "$url?action=screenshot&name={$vzid}";
*/
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
/*
    if [ -e /etc/dhcp/dhcpd.vps ]; then
    	DHCPVPS=/etc/dhcp/dhcpd.vps
    else
    	DHCPVPS=/etc/dhcpd.vps
    fi
    if [ -e /etc/apt ]; then
        DHCPSERVICE=isc-dhcp-server
    else
        DHCPSERVICE=dhcpd
    fi
    /bin/cp -f $DHCPVPS $DHCPVPS.backup;
    grep -v -e "host {$vzid} " -e "fixed-address $ip;" $DHCPVPS.backup > $DHCPVPS
    echo "host {$vzid} { hardware ethernet $mac; fixed-address $ip; }" >> $DHCPVPS
    rm -f $DHCPVPS.backup;
    systemctl restart $DHCPSERVICE 2>/dev/null || service $DHCPSERVICE restart 2>/dev/null || /etc/init.d/$DHCPSERVICE restart 2>/dev/null
*/
    }

    public function install_gz_image($source, $device) {
    	/*
	echo "Copying $source Image"
	tsize=$(stat -c%s "$source")
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
		/*
	echo "Copying Image";
	tsize=$(stat -c%s "$source");
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
	rm -f dd.progress;
	*/
	}

	public function convert_id_to_mac($id, $module) {
		$prefix = $module == 'quickservers' ? '00:0C:29' : '00:16:3E';
		$suffix = strtoupper(sprintf("%06s", dechex($id)));
		$mac = $prefix.':'.substr($suffix, 0, 2).':'.substr($suffix, 2, 2).':'.substr($suffix, 4, 2);
		return $mac;
	}

}
