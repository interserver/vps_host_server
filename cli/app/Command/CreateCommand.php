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
	public $virtBins = [
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
	public $virts = [];
	public $base = '/root/cpaneldirect';
	public $cpu = 1;
	public $ram = 1024;
	public $hd = 25;
	public $maxCpu = 8;
	public $useAll = false;
	public $hostname = '';
	public $template = '';
	public $device = '';
	public $pool = '';
	public $ip = '';
	public $extraips = [];
    public $softraid = [];
    public $error = 0;
    public $adjust_partitions = 1;

	public function brief() {
		return "Creates a Virtual Machine.";
	}

    public function usage()
    {
        return <<<HELP
Creates a new VPS with the given <hostname> and primary IP address <ip>.  The <template> file/url is used as the source image to copy to the VPS.
HELP;
    }

    public function help()
    {
        return <<<HELP
<bold>bold text</bold>
<underline>underlined text</underline>
HELP;
    }

    /**
    * @param \GetOptionKit\OptionCollection $opts
    */
	public function options($opts) {
		parent::options($opts);
        $opts->add('m|mac:', 'MAC Address')
        	->isa('string');
        $opts->add('slices:', 'Number of Slices for the vps')
        	->isa('number');
        $opts->add('id:', 'Order ID # for the vps')
        	->isa('number');
        $opts->add('ips:', 'Additional IPs')
        	->isa('string');
        $opts->add('e|email:', 'Email Address of the VPS owner')
        	->isa('string');
        $opts->add('custid:', 'Customer ID #')
        	->isa('number');
        $opts->add('clientip:', 'Client IP')
        	->isa('ip');
		$opts->add('all', 'Use All Available HD, CPU Cores, and 70% RAM');
	}

    /**
    * @param \CLIFramework\ArgInfoList $args
    */
	public function arguments($args) {
		$args->add('hostname')
			->desc('Hostname to use')
			->isa('string');
		$args->add('ip')
			->desc('IP Address')
			->isa('ip');
		$args->add('template')
			->desc('Install Image To Use')
			->isa('string');
		$args->add('hd')
			->desc('HD Size in GB')
			->optional()
			->isa('number');
		$args->add('ram')
			->desc('Ram In MB')
			->optional()
			->isa('number');
		$args->add('cpu')
			->desc('Number of CPUs/Cores')
			->optional()
			->isa('number');
		$args->add('pass')
			->desc('Root/Administrator password')
			->optional()
			->isa('string');
	}

    public function getInstalledVirts() {
		$found = [];
		foreach ($this->virtBins as $virt => $virtBin) {
			if (file_exists($virtBin)) {
				$found[] = $virt;
			}
		}
		return $found;
    }

    public function isVirtualHost() {
		$virts = $this->getInstalledVirts();
		return count($virts) > 0;
    }

	public function execute($hostname, $ip, $template, $hd = 25, $ram = 1024, $cpu = 1, $pass = '') {
		if (!$this->isVirtualHost()) {
			$this->getLogger()->writeln("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->writeln("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		/* Initialize Variables and process Options and Arguments */
		$this->hostname = $hostname;
		$this->ip = $ip;
		$this->template = $template;
		$this->hd = $hd;
		$this->ram = $ram;
		$this->cpu = $cpu;
		$this->pass = $pass;
		/**
		* @var {\GetOptionKit\OptionResult|GetOptionKit\OptionCollection}
		*/
		$opts = $this->getOptions();
        $this->useAll = array_key_exists('all', $opts->keys) && $opts->keys['all']['value'] == 1;
		/* convert ram to kb */
		$this->ram = $this->ram * 1024;
		/* convert hd to mb */
		$this->hd = $this->hd * 1024;
        $this->maxCpu = $this->cpu > 8 ? $this->cpu : 8;
    	$maxMemory = $this->ram > 16384000 ? $this->ram : 16384000;
        if ($this->useAll == true) {
			$this->hd = 'all';
			$this->ram = $this->getUsableRam();
			$this->cpu = $this->getCpuCount();
        }
        $kpartsOpts = preg_match('/sync/', `kpartx 2>&1`) ? '-s' : '';
        $this->progress(1);
    	$this->checkDeps();
		$this->progress(3);
		$this->device = '/dev/vz/'.$this->hostname;
		$this->pool = $this->getPoolType();
		print_r($opts);
		echo "Slice HD:".$opts->sliceHd."\n";
		print_r(array_keys($opts->keys));
		$this->getLogger()->writeln("Got here");
		return;
		if ($this->pool == 'zfs') {
			mkdir('/vz/'.$this->hostname, 0777, true);
			echo `zfs create vz/{$this->hostname}`;
			$this->device='/vz/'.$this->hostname.'/os.qcow2';
			while (!file_exists('/vz/'.$this->hostname)) {
				sleep(1);
			}
			//virsh vol-create-as --pool vz --name {$this->hostname}/os.qcow2 --capacity "$this->hd"M --format qcow2 --prealloc-metadata
			//sleep 5s;
			//device="$(virsh vol-list vz --details|grep " {$this->hostname}[/ ]"|awk '{ print $2 }')"
		} else {
			echo `{$this->base}/vps_kvm_lvmcreate.sh {$this->hostname} {$this->hd}`;
			// exit here on failed exit status
		}
		$this->progress(1);
		echo "{$this->pool} pool device {$this->device} created\n";
		$this->defineVps();
		$mac = xml2array(`/usr/bin/virsh dumpxml {$this->hostname};`)['domain']['devices']['interface']['mac_attr']['address'];
		$this->setupDhcpd($this->hostname, $this->ip, $mac);
		$this->progress(15);
		echo "Custid is {$custid}\n";
		if ($custid == 565600) {
			if (!file_exists('/vz/templates/template.281311.qcow2')) {
				echo `wget -O /vz/templates/template.281311.qcow2 http://kvmtemplates.is.cc/cl/template.281311.qcow2;`;
			}
			$this->template = 'template.281311';
		}
		if ($this->pool == 'zfs') {
			// kvmv2
			if (file_exists('/vz/templates/'.$this->template.'.qcow2') || $this->template == 'empty') {
				echo "Copy {$this->template}.qcow2 Image\n";
				if ($this->hd == 'all') {
					$this->hd = intval(`zfs list vz -o available -H -p`) / (1024 * 1024);
					if ($this->hd > 2000000)
						$this->hd = 2000000;
				}
				if (stripos($this->template, 'freebsd') !== false) {
					echo `cp -f /vz/templates/{$this->template}.qcow2 {$this->device};`;
					$this->progress(60);
					echo `qemu-img resize {$this->device} "{$this->hd}"M;`;
				} else {
					echo `qemu-img create -f qcow2 -o preallocation=metadata {$this->device} 25G;`;
					$this->progress(40);
					echo `qemu-img resize {$this->device} "{$this->hd}"M;`;
					$this->progress(70);
					if ($this->template != 'empty') {
						$part = `virt-list-partitions /vz/templates/{$this->template}.qcow2|tail -n 1;`;
						$backuppart = `virt-list-partitions /vz/templates/{$this->template}.qcow2|head -n 1;`;
						if ($this->template != 'template.281311') {
							echo `virt-resize --expand {$part} /vz/templates/{$this->template}.qcow2 {$this->device} || virt-resize --expand {$backuppart} /vz/templates/{$this->template}.qcow2 {$this->device} ;`;
						} else {
							echo `cp -fv /vz/templates/{$this->template}.qcow2 {$this->device};`;
						}
					}
				}
				$this->progress(90);
				echo `virsh detach-disk {$this->hostname} vda --persistent;`;
				echo `virsh attach-disk {$this->hostname} /vz/{$this->hostname}/os.qcow2 vda --targetbus virtio --driver qemu --subdriver qcow2 --type disk --sourcetype file --persistent;`;
				echo `virsh dumpxml {$this->hostname} > vps.xml`;
				echo `sed s#"type='qcow2'/"#"type='qcow2' cache='writeback' discard='unmap'/"#g -i vps.xml`;
				echo `virsh define vps.xml`;
				echo `rm -f vps.xml`;
				echo `virt-customize -d {$this->hostname} --root-password password:{$rootpass} --hostname "{$this->hostname}";`;
				$this->adjust_partitions = 0;
			}
		} elseif (substr($this->template, 0, 7) == 'http://' || substr($this->template, 0, 8) == 'https://' || substr($this->template, 0, 6) == 'ftp://') {
			// image from url
			$this->adjust_partitions = 0;
			echo "Downloading {$this->template} Image\n";
			echo `{$this->base}/vps_get_image.sh "{$this->template}"`;
			if (!file_exists('/image_storage/image.raw.img')) {
				echo "There must have been a problem, the image does not exist\n";
				$this->error++;
			} else {
				$this->install_image('/image_storage/image.raw.img', $this->device);
				echo "Removing Downloaded Image\n";
				echo `umount /image_storage;`;
				echo `virsh vol-delete --pool vz image_storage;`;
				echo `rmdir /image_storage;`;
			}
		} elseif ($this->template == 'empty') {
			// kvmv1 install empty image
			$this->adjust_partitions = 0;
		} else {
			// kvmv1 install
			$found = 0;
			foreach (['/vz/templates/', '/templates/', '/'] as $prefix) {
				$source = $prefix.$this->template.'.img.gz';
				if ($found == 0 && file_exists($source)) {
					$found = 1;
					$this->install_gz_image($source, $this->device);
				}
			}
			foreach (['/vz/templates/', '/templates/', '/', '/dev/vz/'] as $prefix) {
				foreach (['.img', ''] as $suffix) {
					$source = $prefix.$this->template.$suffix;
					if ($found == 0 && file_exists($source)) {
						$found = 1;
						$this->install_image($source, $this->device);
					}
				}
			}
			if ($found == 0) {
				echo "Template does not exist\n";
				$this->error++;
			}
		}
		if (count($this->softraid) > 0) {
			foreach ($this->softraid as $softfile) {
				file_put_contents($softfile, 'check');
			}
		}
		echo "Errors: {$this->error}  Adjust Partitions: {$this->adjust_partitions}\n";
		if ($this->error == 0) {
			if ($this->adjust_partitions == 1) {
				$this->progress('resizing');
				$sects = trim(`fdisk -l -u $this->device  | grep sectors$ | sed s#"^.* \([0-9]*\) sectors$"#"\1"#g`);
				$t = trim(`fdisk -l -u $this->device | sed s#"\*"#""#g | grep "^$this->device" | tail -n 1`);
				$p = trim(`echo $t | awk '{ print $1 }'`);
				$fs = trim(`echo $t | awk '{ print $5 }'`);
				if (trim(`echo "$fs" | grep "[A-Z]")`) != '') {
					$fs = trim(`echo $t | awk '{ print $6 }'`);
				}
				$pn = trim(`echo "$p" | sed s#"$this->device[p]*"#""#g`);
				$pt = $pn > 4 ? 'l' : 'p';
				$start = trim(`echo $t | awk '{ print $2 }'`);
				if ($fs == 83) {
					echo "Resizing Last Partition To Use All Free Space (Sect $sects P {$p} FS {$fs} PN {$pn} PT {$pt} Start {$start}\n";
					echo `echo -e "d\n{$pn}\nn\n{$pt}\n{$pn}\n{$start}\n\n\nw\nprint\nq\n" | fdisk -u {$this->device}`;
					echo `kpartx $kpartxopts -av {$this->device}`;
					$pname = trim(`ls /dev/mapper/vz-"{$this->hostname}"p{$pn} /dev/mapper/vz-{$this->hostname}{$pn} /dev/mapper/"{$this->hostname}"p{$pn} /dev/mapper/{$this->hostname}{$pn} 2>/dev/null | cut -d/ -f4 | sed s#"{$pn}$"#""#g`);
					echo `e2fsck -p -f /dev/mapper/{$pname}{$pn}`;
					$resizefs = trim(`which resize4fs 2>/dev/null`) != '' ? 'resize4fs' : 'resize2fs';
					echo `$resizefs -p /dev/mapper/{$pname}{$pn}`;
					mkdir('/vz/mounts/'.$this->hostname.$pn, 0777, true);
					echo `mount /dev/mapper/{$pname}{$pn} /vz/mounts/{$this->hostname}{$pn};`;
					echo `echo root:{$rootpass} | chroot /vz/mounts/{$this->hostname}{$pn} chpasswd || php {$this->base}/vps_kvm_password_manual.php {$rootpass} "/vz/mounts/{$this->hostname}{$pn}"`;
					if (file_exists('/vz/mounts/'.$this->hostname.$pn.'/home/kvm')) {
						echo `echo kvm:{$rootpass} | chroot /vz/mounts/{$this->hostname}{$pn} chpasswd`;
					}
					echo `umount /dev/mapper/{$pname}{$pn}`;
					echo `kpartx $kpartxopts -d {$this->device}`;
				} else {
					echo "Skipping Resizing Last Partition FS is not 83. Space (Sect $sects P {$p} FS {$fs} PN {$pn} PT {$pt} Start {$start}\n";
				}
			}
			touch('/tmp/_securexinetd');
			echo `/usr/bin/virsh autostart {$this->hostname};`;
			$this->progress('starting');
			echo `/usr/bin/virsh start {$this->hostname};`;
			if ($this->pool != 'zfs') {
				echo `bash {$this->base}/run_buildebtables.sh;`;
			}
			if ($this->useAll == false) {
				if (!file_exists('/cgroup/blkio/libvirt/qemu')) {
					echo "CGroups not detected\n";
				} else {
					$cpushares = $slices * 512;
					$ioweight = 400 + (37 * $slices);
					echo `virsh schedinfo {$this->hostname} --set cpu_shares={$cpushares} --current;`;
					echo `virsh schedinfo {$this->hostname} --set cpu_shares={$cpushares} --config;`;
					echo `virsh blkiotune {$this->hostname} --weight {$ioweight} --current;`;
					echo `virsh blkiotune {$this->hostname} --weight {$ioweight} --config;`;
				}
			}
			echo `{$this->base}/tclimit {$this->ip};`;
			if ($clientip != '') {
				$clientip = escapeshellarg($clientip);
				echo `{$this->base}/vps_kvm_setup_vnc.sh {$this->hostname} {$clientip};`;
			}
			echo `{$this->base}/vps_refresh_vnc.sh {$this->hostname};`;
			$vnc = trim(`virsh vncdisplay {$this->hostname} | cut -d: -f2 | head -n 1`);
			if ($vnc == '') {
				sleep(2);
				$vnc = trim(`virsh vncdisplay {$this->hostname} | cut -d: -f2 | head -n 1`);
				if ($vnc == '') {
					sleep(2);
					$vnc = trim(`virsh dumpxml {$this->hostname} |grep -i "graphics type='vnc'" | cut -d\' -f4`);
				} else {
					$vnc += 5900;
				}
			} else {
				$vnc += 5900;
			}
			$vnc -= 5900;
			echo `{$this->base}/vps_kvm_screenshot.sh "{$vnc}" "$url?action=screenshot&name={$this->hostname}";`;
			sleep(1);
			echo `{$this->base}/vps_kvm_screenshot.sh "{$vnc}" "$url?action=screenshot&name={$this->hostname}";`;
			sleep(1);
			echo `{$this->base}/vps_kvm_screenshot.sh "{$vnc}" "$url?action=screenshot&name={$this->hostname}";`;
			$vnc += 5900;
			echo `/admin/kvmenable blocksmtp {$this->hostname};`;
			echo `rm -f /tmp/_securexinetd;`;
			if ($this->useAll == false) {
				echo `/admin/kvmenable ebflush;`;
				echo `{$this->base}/buildebtablesrules | sh;`;
			}
			echo `service xinetd restart`;
		}
		$this->progress(100);
	}

    public function progress($progress) {

    }

    public function setupDhcpd() {
		$dhcpvps = file_exists('/etc/dhcp/dhcpd.vps') ? '/etc/dhcp/dhcpd.vps' : '/etc/dhcpd.vps';
		$dhcpservice = file_exists('/etc/apt') ? 'isc-dhcp-server' : 'dhcpd';
		echo `/bin/cp -f {$dhcpvps} {$dhcpvps}.backup;`;
    	echo `grep -v -e "host {$this->hostname} " -e "fixed-address {$this->ip};" {$dhcpvps}.backup > {$dhcpvps}`;
    	echo `echo "host {$this->hostname} { hardware ethernet {$mac}; fixed-address {$this->ip}; }" >> {$dhcpvps}`;
    	echo `rm -f {$dhcpvps}.backup;`;
    	echo `systemctl restart {$dhcpservice} 2>/dev/null || service {$dhcpservice} restart 2>/dev/null || /etc/init.d/{$dhcpservice} restart 2>/dev/null`;
    }

    public function install_gz_image($source, $device) {
    	echo "Copying {$source} Image\n";
    	$tsize = trim(`stat -c%s "{$source}"`);
    	echo `gzip -dc "/{$source}"  | dd of={$device} 2>&1`;
    	/*
	gzip -dc "/$source"  | dd of=$this->device 2>&1 &
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
/*			for softfile in $this->softraid; do
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
	dd "if=$source" "of=$this->device" >dd.progress 2>&1 &
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
/*				for softfile in $this->softraid; do
					echo idle > $softfile;
				done;
			fi;
			echo "$completed%";
		fi;
	done;
	*/
		echo `rm -f dd.progress;`;
	}

	public function convert_id_to_mac($id, $useAll) {
		$prefix = $useAll == true ? '00:0C:29' : '00:16:3E';
		$suffix = strtoupper(sprintf("%06s", dechex($id)));
		$mac = $prefix.':'.substr($suffix, 0, 2).':'.substr($suffix, 2, 2).':'.substr($suffix, 4, 2);
		return $mac;
	}

	public function defineVps() {
		if ($this->vpsExists($this->hostname)) {
			echo `/usr/bin/virsh destroy {$this->hostname}`;
			echo `cp {$this->hostname}.xml {$this->hostname}.xml.backup`;
			echo `/usr/bin/virsh undefine {$this->hostname}`;
			echo `mv -f {$this->hostname}.xml.backup {$this->hostname}.xml`;
		} else {
			echo "Generating XML Config\n";
			if ($this->pool != 'zfs') {
				echo `grep -v -e uuid -e filterref -e "<parameter name='IP'" {$this->base}/windows.xml | sed s#"windows"#"{$this->hostname}"#g > {$this->hostname}.xml`;
			} else {
				echo `grep -v -e uuid {$this->base}/windows.xml | sed -e s#"windows"#"{$this->hostname}"#g -e s#"/dev/vz/{$this->hostname}"#"{$this->device}"#g > {$this->hostname}.xml`;
			}
			if (!file_exists('/usr/libexec/qemu-kvm') && file_exists('/usr/bin/kvm')) {
				echo `sed s#"/usr/libexec/qemu-kvm"#"/usr/bin/kvm"#g -i {$this->hostname}.xml`;
			}
		}
		if ($this->useAll == true) {
			echo `sed -e s#"^.*<parameter name='IP.*$"#""#g -e  s#"^.*filterref.*$"#""#g -i {$this->hostname}.xml`;
		} else {
			$repl = "<parameter name='IP' value='{$this->ip}'/>";
			if (count($this->extraips) > 0)
				foreach ($this->extraips as $extraIp)
					$repl = "{$repl}\n        <parameter name='IP' value='{$extraIp}'/>";
			echo `sed s#"<parameter name='IP' value.*/>"#"{$repl}"#g -i {$this->hostname}.xml;`;
		}
		/* convert hostname to id */
		$id = str_replace(['qs', 'windows', 'linux', 'vps'], ['', '', '', ''], $this->hostname);
		/* use id to generate mac address if we a numeric id or remove mac otherwise */
		if (is_numeric($id)) {
			$mac = $this->convert_id_to_mac($id, $this->useAll);
			echo `sed s#"<mac address='.*'"#"<mac address='{$mac}'"#g -i {$this->hostname}.xml`;
		} else {
			echo `sed s#"^.*<mac address.*$"#""#g -i {$this->hostname}.xml`;
		}
		echo `sed s#"<\(vcpu.*\)>.*</vcpu>"#"<vcpu placement='static' current='{$this->cpu}'>{$this->maxCpu}</vcpu>"#g -i {$this->hostname}.xml;`;
		echo `sed s#"<memory.*memory>"#"<memory unit='KiB'>{$this->ram}</memory>"#g -i {$this->hostname}.xml;`;
		echo `sed s#"<currentMemory.*currentMemory>"#"<currentMemory unit='KiB'>{$this->ram}</currentMemory>"#g -i {$this->hostname}.xml;`;
		if (trim(`grep -e "flags.*ept" -e "flags.*npt" /proc/cpuinfo`) != '') {
			echo `sed s#"<features>"#"<features>\n    <hap/>"#g -i {$this->hostname}.xml;`;
		}
		if (trim(`date "+%Z"`) == 'PDT') {
			echo `sed s#"America/New_York"#"America/Los_Angeles"#g -i {$this->hostname}.xml;`;
		}
		if (file_exists('/etc/lsb-release')) {
			if (substr($this->template, 0, 7) == 'windows') {
				echo `sed -e s#"</features>"#"  <hyperv>\n      <relaxed state='on'/>\n      <vapic state='on'/>\n      <spinlocks state='on' retries='8191'/>\n    </hyperv>\n  </features>"#g -i {$this->hostname}.xml;`;
				echo `sed -e s#"<clock offset='timezone' timezone='\([^']*\)'/>"#"<clock offset='timezone' timezone='\1'>\n    <timer name='hypervclock' present='yes'/>\n  </clock>"#g -i {$this->hostname}.xml;`;
			}
			echo `sed s#"\(<controller type='scsi' index='0'.*\)>"#"\1 model='virtio-scsi'>\n      <driver queues='{$this->cpu}'/>"#g -i  {$this->hostname}.xml;`;
		}
		echo `/usr/bin/virsh define {$this->hostname}.xml;`;
		//echo `/usr/bin/virsh setmaxmem {$this->hostname} $this->ram;`;
		//echo `/usr/bin/virsh setmem {$this->hostname} $this->ram;`;
		//echo `/usr/bin/virsh setvcpus {$this->hostname} $this->cpu;`;
	}

	public function vpsExists($hostname) {
		passthru('/usr/bin/virsh dominfo '.$hostname.' >/dev/null 2>&1', $return);
		return $return > 0;
	}

	public function checkDeps() {
    	if ($this->isRedhatBased() && $this->getRedhatVersion() < 7) {
			if ($this->getE2fsprogsVersion() <= 1.41) {
				if (!file_exists('/opt/e2fsprogs/sbin/e2fsck')) {
					echo `/admin/ports/install e2fsprogs;`;
				}
			}
    	}
	}

	public function getE2fsprogsVersion() {
		return floatval(trim(`e2fsck -V 2>&1 |head -n 1 | cut -d" " -f2 | cut -d"." -f1-2`));
	}

	public function getRedhatVersion() {
		return floatval(trim(`cat /etc/redhat-release |sed s#"^[^0-9]* \([0-9\.]*\).*$"#"\1"#g`));
	}

	public function isRedhatBased() {
		return file_exists('/etc/redhat-release');
	}

	public function getPoolType() {
		$pool = xml2array(file_get_contents(`virsh pool-dumpxml vz 2>/dev/null`))['pool_attr']['type'];
		if ($pool == '') {
			echo `{$this->base}/create_libvirt_storage_pools.sh`;
			$pool = xml2array(file_get_contents(`virsh pool-dumpxml vz 2>/dev/null`))['pool_attr']['type'];
		}
		if (preg_match('/vz/', `virsh pool-list --inactive`)) {
			echo `virsh pool-start vz;`;
		}
		return $pool;
	}

	public function getTotalRam() {
		preg_match('/^MemTotal:\s+(\d+)\skB/', file_get_contents('/proc/meminfo'), $matches);
		$ram = floatval($matches[1]);
		return $ram;
	}

	public function getUsableRam() {
		$ram = floor($this->getTotalRam() / 100 * 70);
		return $ram;
	}

	public function getCpuCount() {
		preg_match('/CPU\(s\):\s+(\d+)/', `lscpu`, $matches);
		return intval($matches[1]);
	}

}
