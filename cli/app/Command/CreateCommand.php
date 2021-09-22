<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;
use CLIFramework\Component\Progress\ProgressBar;

class CreateCommand extends Command {
    /* log levels: critical[1] error[2] warn[3] info[4] info2[5] debug[6] debug2[7] (default: 4, below current shown) */
	public $base = '/root/cpaneldirect';
	public $cpu = 1;
	public $ram = 1024;
	public $hd = 25;
	public $maxCpu = 8;
	public $maxRam = 16384000;
	public $useAll = false;
	public $hostname = '';
	public $template = '';
	public $device = '';
	public $pool = '';
	public $ip = '';
	public $mac = '';
	public $orderId = '';
	public $password = '';
	public $extraIps = [];
    public $softraid = [];
    public $error = 0;
    public $adjust_partitions = 1;
    public $vncPort = '';
    public $clientIp = '';
    public $url = '';
    public $kpartxOpts = '';

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

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
        $opts->add('m|mac:', 'MAC Address')->isa('string');
        $opts->add('o|order-id:', 'Order ID')->isa('number');
        $opts->add('i|add-ip+', 'Additional IPs')->multiple()->isa('string');
        $opts->add('c|client-ip:', 'Client IP')->isa('ip');
		$opts->add('a|all', 'Use All Available HD, CPU Cores, and 70% RAM');
		$opts->add('v|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('ip')->desc('IP Address')->isa('ip');
		$args->add('template')->desc('Install Image To Use')->isa('string');
		$args->add('hd')->desc('HD Size in GB')->optional()->isa('number');
		$args->add('ram')->desc('Ram In MB')->optional()->isa('number');
		$args->add('cpu')->desc('Number of CPUs/Cores')->optional()->isa('number');
		$args->add('password')->desc('Root/Administrator password')->optional()->isa('string');
	}

	public function execute($hostname, $ip, $template, $hd = 25, $ram = 1024, $cpu = 1, $password = '') {
		Vps::init($this->getArgInfoList(), func_get_args(), $this->getOptions());
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->writeln("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->writeln("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		$this->initVariables($hostname, $ip, $template, $hd, $ram, $cpu, $password);
    	$this->checkDeps();
		$this->setupStorage();
		$this->defineVps();
		Vps::setupDhcpd($this->hostname, $this->ip, $this->mac);
		$this->progress(25);
		$this->installTemplate();
		$this->startupVps();
		$this->setupCgroups();
		$this->setupRouting();
		$this->setupVnc();
	}

    public function initVariables($hostname, $ip, $template, $hd, $ram, $cpu, $password) {
		/** @var {\GetOptionKit\OptionResult|GetOptionKit\OptionCollection} */
		$opts = $this->getOptions();
		$this->getLogger()->info('Initializing Variables and process Options and Arguments');
		$this->hostname = $hostname;
		$this->ip = $ip;
		$this->template = $template;
		$this->hd = $hd;
		$this->ram = $ram;
		$this->cpu = $cpu;
		$this->password = $password;
        $this->useAll = array_key_exists('all', $opts->keys) && $opts->keys['all']['value'] == 1;
        $this->extraIps = array_key_exists('add-ip', $opts->keys) ? $opts->keys['add-ip']->value : [];
        $this->clientIp = array_key_exists('client-ip', $opts->keys) ? $opts->keys['client-ip']->value : '';
		$this->orderId = array_key_exists('order-id', $opts->keys) ? $opts->keys['order-id']->value : '';
        $this->mac = array_key_exists('mac', $opts->keys) ? $opts->keys['mac']->value : '';
		if ($this->orderId == '')
			$this->orderId = str_replace(['qs', 'windows', 'linux', 'vps'], ['', '', '', ''], $this->hostname); // convert hostname to id
		if ($this->mac == '' && is_numeric($this->orderId))
			$this->mac = Vps::convertIdToMac($this->orderId, $this->useAll); // use id to generate mac address
        $this->url = $this->useAll == true ? 'https://myquickserver.interserver.net/qs_queue.php' : 'https://myvps.interserver.net/vps_queue.php';
        $this->kpartsOpts = preg_match('/sync/', Vps::runCommand("kpartx 2>&1")) ? '-s' : '';
		$this->ram = $this->ram * 1024; // convert ram to kb
		$this->hd = $this->hd * 1024; // convert hd to mb
        if ($this->useAll == true) {
			$this->hd = 'all';
			$this->ram = Vps::getUsableRam();
			$this->cpu = Vps::getCpuCount();
        }
        $this->maxCpu = $this->cpu > 8 ? $this->cpu : 8;
    	$this->maxRam = $this->ram > 16384000 ? $this->ram : 16384000;
		$this->pool = Vps::getPoolType();
		$this->device = $this->pool == 'zfs' ? '/vz/'.$this->hostname.'/os.qcow2' : '/dev/vz/'.$this->hostname;
		//$this->getLogger()->info2(print_r($opts, true));
        $this->progress(5);
    }

    public function progress($progress) {
    	$progress = escapeshellarg($progress);
    	Vps::runCommand("curl --connect-timeout 10 --max-time 20 -k -d action=install_progress -d progress={$progress} -d server={$this->orderId} '{$this->url}' < /dev/null > /dev/null 2>&1;");
		$this->getLogger()->writeln($progress.'%');
    }

	public function checkDeps() {
		$this->getLogger()->info('Checking for dependancy failures and fixing them');
    	if (Vps::isRedhatBased() && Vps::getRedhatVersion() < 7) {
			if (Vps::getE2fsprogsVersion() <= 1.41) {
				if (!file_exists('/opt/e2fsprogs/sbin/e2fsck')) {
					echo Vps::runCommand("/admin/ports/install e2fsprogs;");
				}
			}
    	}
		$this->progress(10);
	}

	public function startupVps() {
		if ($this->error == 0) {
			$this->getLogger()->info('Enabling and Starting up the VPS');
			Vps::enableAutostart($this->hostname);
			echo Vps::runCommand("/usr/bin/virsh start {$this->hostname};");
			$this->progress(85);
		}
	}

	public function setupStorage() {
		$this->getLogger()->info('Creating Storage Pool');
		if ($this->pool == 'zfs') {
			echo Vps::runCommand("zfs create vz/{$this->hostname}");
			@mkdir('/vz/'.$this->hostname, 0777, true);
			while (!file_exists('/vz/'.$this->hostname)) {
				sleep(1);
			}
			//virsh vol-create-as --pool vz --name {$this->hostname}/os.qcow2 --capacity "$this->hd"M --format qcow2 --prealloc-metadata
			//sleep 5s;
			//device="$(virsh vol-list vz --details|grep " {$this->hostname}[/ ]"|awk '{ print $2 }')"
		} else {
			echo Vps::runCommand("{$this->base}/vps_kvm_lvmcreate.sh {$this->hostname} {$this->hd}");
			// exit here on failed exit status
		}
		$this->getLogger()->info("{$this->pool} pool device {$this->device} created");
		$this->progress(15);
	}

	public function defineVps() {
		$this->getLogger()->info('Creating VPS Definition');
		$this->getLogger()->indent();
		if (Vps::vpsExists($this->hostname)) {
			echo Vps::runCommand("/usr/bin/virsh destroy {$this->hostname}");
			echo Vps::runCommand("cp {$this->hostname}.xml {$this->hostname}.xml.backup");
			echo Vps::runCommand("/usr/bin/virsh undefine {$this->hostname}");
			echo Vps::runCommand("mv -f {$this->hostname}.xml.backup {$this->hostname}.xml");
		} else {
			if ($this->pool != 'zfs') {
				$this->getLogger()->debug('Removing UUID Filterref and IP information');
				echo Vps::runCommand("grep -v -e uuid -e filterref -e \"<parameter name='IP'\" {$this->base}/windows.xml | sed s#\"windows\"#\"{$this->hostname}\"#g > {$this->hostname}.xml");
			} else {
				$this->getLogger()->debug('Removing UUID information');
				echo Vps::runCommand("grep -v -e uuid {$this->base}/windows.xml | sed -e s#\"windows\"#\"{$this->hostname}\"#g -e s#\"/dev/vz/{$this->hostname}\"#\"{$this->device}\"#g > {$this->hostname}.xml");
			}
			if (!file_exists('/usr/libexec/qemu-kvm') && file_exists('/usr/bin/kvm')) {
				$this->getLogger()->debug('Replacing KVM Binary Path');
				echo Vps::runCommand("sed s#\"/usr/libexec/qemu-kvm\"#\"/usr/bin/kvm\"#g -i {$this->hostname}.xml");
			}
		}
		if ($this->useAll == true) {
			$this->getLogger()->debug('Removing IP information');
			echo Vps::runCommand("sed -e s#\"^.*<parameter name='IP.*$\"#\"\"#g -e  s#\"^.*filterref.*$\"#\"\"#g -i {$this->hostname}.xml");
		} else {
			$this->getLogger()->debug('Replacing UUID Filterref and IP information');
			$repl = "<parameter name='IP' value='{$this->ip}'/>";
			if (count($this->extraIps) > 0)
				foreach ($this->extraIps as $extraIp)
					$repl = "{$repl}\\n        <parameter name='IP' value='{$extraIp}'/>";
			echo Vps::runCommand("sed s#\"<parameter name='IP' value.*/>\"#\"{$repl}\"#g -i {$this->hostname}.xml;");
		}
		if ($this->mac != '') {
			$this->getLogger()->debug('Replacing MAC addresss');
			echo Vps::runCommand("sed s#\"<mac address='.*'\"#\"<mac address='{$this->mac}'\"#g -i {$this->hostname}.xml");
		} else {
			$this->getLogger()->debug('Removing MAC address');
			echo Vps::runCommand("sed s#\"^.*<mac address.*$\"#\"\"#g -i {$this->hostname}.xml");
		}
		$this->getLogger()->debug('Setting CPU limits');
		echo Vps::runCommand("sed s#\"<\(vcpu.*\)>.*</vcpu>\"#\"<vcpu placement='static' current='{$this->cpu}'>{$this->maxCpu}</vcpu>\"#g -i {$this->hostname}.xml;");
		$this->getLogger()->debug('Setting Max Memory limits');
		echo Vps::runCommand("sed s#\"<memory.*memory>\"#\"<memory unit='KiB'>{$this->maxRam}</memory>\"#g -i {$this->hostname}.xml;");
		$this->getLogger()->debug('Setting Memory limits');
		echo Vps::runCommand("sed s#\"<currentMemory.*currentMemory>\"#\"<currentMemory unit='KiB'>{$this->ram}</currentMemory>\"#g -i {$this->hostname}.xml;");
		if (trim(Vps::runCommand("grep -e \"flags.*ept\" -e \"flags.*npt\" /proc/cpuinfo")) != '') {
			$this->getLogger()->debug('Adding HAP features flag');
			echo Vps::runCommand("sed s#\"<features>\"#\"<features>\\n    <hap/>\"#g -i {$this->hostname}.xml;");
		}
		if (trim(Vps::runCommand("date \"+%Z\"")) == 'PDT') {
			$this->getLogger()->debug('Setting Timezone to PST');
			echo Vps::runCommand("sed s#\"America/New_York\"#\"America/Los_Angeles\"#g -i {$this->hostname}.xml;");
		}
		if (file_exists('/etc/lsb-release')) {
			if (substr($this->template, 0, 7) == 'windows') {
				$this->getLogger()->debug('Adding HyperV block');
				echo Vps::runCommand("sed -e s#\"</features>\"#\"  <hyperv>\\n      <relaxed state='on'/>\\n      <vapic state='on'/>\\n      <spinlocks state='on' retries='8191'/>\\n    </hyperv>\\n  </features>\"#g -i {$this->hostname}.xml;");
			$this->getLogger()->debug('Adding HyperV timer');
					echo Vps::runCommand("sed -e s#\"<clock offset='timezone' timezone='\([^']*\)'/>\"#\"<clock offset='timezone' timezone='\\1'>\\n    <timer name='hypervclock' present='yes'/>\\n  </clock>\"#g -i {$this->hostname}.xml;");
			}
			$this->getLogger()->debug('Customizing SCSI controller');
			echo Vps::runCommand("sed s#\"\(<controller type='scsi' index='0'.*\)>\"#\"\\1 model='virtio-scsi'>\\n      <driver queues='{$this->cpu}'/>\"#g -i  {$this->hostname}.xml;");
		}
		echo Vps::runCommand("/usr/bin/virsh define {$this->hostname}.xml");
		echo Vps::runCommand("rm -f {$this->hostname}.xml");
		//echo Vps::runCommand("/usr/bin/virsh setmaxmem {$this->hostname} $this->ram;");
		//echo Vps::runCommand("/usr/bin/virsh setmem {$this->hostname} $this->ram;");
		//echo Vps::runCommand("/usr/bin/virsh setvcpus {$this->hostname} $this->cpu;");
		$this->getLogger()->unIndent();
		$this->progress(20);
	}

	public function installTemplate() {
		$this->getLogger()->info('Installing OS Template');
		return $this->pool == 'zfs' ? $this->installTemplateV2() : $this->installTemplateV1();
	}


	public function setupCgroups() {
		if ($this->error == 0) {
			if ($this->useAll == false) {
				$slices = $this->cpu;
				Vps::setupCgroups($this->hostname, $slices);
			}
			$this->progress(90);
		}
	}

	public function setupRouting() {
		if ($this->error == 0) {
			$this->getLogger()->info('Setting up Routing');
			if ($this->useAll == false) {
				Vps::runBuildEbtables();
			}
			echo Vps::runCommand("{$this->base}/tclimit {$this->ip};");
			echo Vps::runCommand("/admin/kvmenable blocksmtp {$this->hostname};");
			if ($this->pool != 'zfs' && $this->useAll == false) {
				echo Vps::runCommand("/admin/kvmenable ebflush;");
				echo Vps::runCommand("{$this->base}/buildebtablesrules | sh;");
			}
			$this->progress(95);
		}
	}

	public function setupVnc() {
		if ($this->error == 0) {
			$this->getLogger()->info('Setting up VNC');
			Vps::lockXinetd();
			if ($this->clientIp != '') {
				$this->clientIp = escapeshellarg($this->clientIp);
				echo Vps::runCommand("{$this->base}/vps_kvm_setup_vnc.sh {$this->hostname} {$this->clientIp};");
			}
			echo Vps::runCommand("{$this->base}/vps_refresh_vnc.sh {$this->hostname};");

			$this->vncPort = Vps::getVncPort($this->hostname);
			$this->vncPort -= 5900;
			echo Vps::runCommand("{$this->base}/vps_kvm_screenshot.sh \"{$this->vncPort}\" \"{$this->url}?action=screenshot&name={$this->hostname}\";");
			sleep(1);
			echo Vps::runCommand("{$this->base}/vps_kvm_screenshot.sh \"{$this->vncPort}\" \"{$this->url}?action=screenshot&name={$this->hostname}\";");
			sleep(1);
			echo Vps::runCommand("{$this->base}/vps_kvm_screenshot.sh \"{$this->vncPort}\" \"{$this->url}?action=screenshot&name={$this->hostname}\";");
			$this->vncPort += 5900;
			Vps::unlockXinetd();
			Vps::restartXinetd();
			$this->progress(100);
		}
	}

	public function installTemplateV2() {
		// kvmv2
		$downloadedTemplate = substr($this->template, 0, 7) == 'http://' || substr($this->template, 0, 8) == 'https://' || substr($this->template, 0, 6) == 'ftp://';
		if ($downloadedTemplate == true) {
			$this->getLogger()->info("Downloading {$this->template} Image");
			echo Vps::runCommand("{$this->base}/vps_get_image.sh \"{$this->template} zfs\"");
			$this->template = 'image';
		}
		if (!file_exists('/vz/templates/'.$this->template.'.qcow2') && $this->template != 'empty') {
			$this->getLogger()->info("There must have been a problem, the image does not exist");
			$this->error++;
			return false;
		} else {
			$this->getLogger()->info("Copy {$this->template}.qcow2 Image");
			if ($this->hd == 'all') {
				$this->hd = intval(trim(Vps::runCommand("zfs list vz -o available -H -p"))) / (1024 * 1024);
				if ($this->hd > 2000000)
					$this->hd = 2000000;
			}
			if (stripos($this->template, 'freebsd') !== false) {
				echo Vps::runCommand("cp -f /vz/templates/{$this->template}.qcow2 {$this->device};");
				$this->progress(60);
				echo Vps::runCommand("qemu-img resize {$this->device} \"{$this->hd}\"M;");
			} else {
				echo Vps::runCommand("qemu-img create -f qcow2 -o preallocation=metadata {$this->device} 25G;");
				$this->progress(40);
				echo Vps::runCommand("qemu-img resize {$this->device} \"{$this->hd}\"M;");
				$this->progress(60);
				if ($this->template != 'empty') {
					$this->getLogger()->debug('Listing Partitions in Template');
					$part = trim(Vps::runCommand("virt-list-partitions /vz/templates/{$this->template}.qcow2|tail -n 1;"));
					$backuppart = trim(Vps::runCommand("virt-list-partitions /vz/templates/{$this->template}.qcow2|head -n 1;"));
					$this->getLogger()->debug('List Partitions got partition '.$part.' and backup partition '.$backuppart);
					$this->getLogger()->debug('Copying and Resizing Template');
					echo Vps::runCommand("virt-resize --expand {$part} /vz/templates/{$this->template}.qcow2 {$this->device} || virt-resize --expand {$backuppart} /vz/templates/{$this->template}.qcow2 {$this->device} || cp -fv /vz/templates/{$this->template}.qcow2 {$this->device}");
				}
			}
			$this->progress(75);
			if ($downloadedTemplate === true) {
				$this->getLogger()->info("Removing Downloaded Image");
				echo Vps::runCommand("rm -f /vz/templates/{$this->template}.qcow2");
			}
			echo Vps::runCommand("virsh detach-disk {$this->hostname} vda --persistent;");
			echo Vps::runCommand("virsh attach-disk {$this->hostname} /vz/{$this->hostname}/os.qcow2 vda --targetbus virtio --driver qemu --subdriver qcow2 --type disk --sourcetype file --persistent;");
			echo Vps::runCommand("virsh dumpxml {$this->hostname} > {$this->hostname}.xml");
			echo Vps::runCommand("sed s#\"type='qcow2'/\"#\"type='qcow2' cache='writeback' discard='unmap'/\"#g -i {$this->hostname}.xml");
			echo Vps::runCommand("virsh define {$this->hostname}.xml");
			echo Vps::runCommand("rm -f {$this->hostname}.xml");
			echo Vps::runCommand("virt-customize -d {$this->hostname} --root-password password:{$this->password} --hostname \"{$this->hostname}\";");
			$this->adjust_partitions = 0;
		}
		$this->progress(80);
	}

	public function installTemplateV1() {
		if (substr($this->template, 0, 7) == 'http://' || substr($this->template, 0, 8) == 'https://' || substr($this->template, 0, 6) == 'ftp://') {
			// image from url
			$this->adjust_partitions = 0;
			$this->getLogger()->info("Downloading {$this->template} Image");
			echo Vps::runCommand("{$this->base}/vps_get_image.sh \"{$this->template}\"");
			if (!file_exists('/image_storage/image.img')) {
				$this->getLogger()->info("There must have been a problem, the image does not exist");
				$this->error++;
				return false;
			} else {
				$this->installImage('/image_storage/image.img', $this->device);
				$this->getLogger()->info("Removing Downloaded Image");
			}
			echo Vps::runCommand("umount /image_storage;");
			echo Vps::runCommand("virsh vol-delete --pool vz image_storage;");
			echo Vps::runCommand("rmdir /image_storage;");
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
					$this->installGzImage($source, $this->device);
				}
			}
			foreach (['/vz/templates/', '/templates/', '/', '/dev/vz/'] as $prefix) {
				foreach (['.img', ''] as $suffix) {
					$source = $prefix.$this->template.$suffix;
					if ($found == 0 && file_exists($source)) {
						$found = 1;
						$this->installImage($source, $this->device);
					}
				}
			}
			if ($found == 0) {
				$this->getLogger()->info("Template does not exist");
				$this->error++;
				return false;
			}
		}
		if (count($this->softraid) > 0) {
			foreach ($this->softraid as $softfile) {
				file_put_contents($softfile, 'check');
			}
		}
		if ($this->error == 0) {
			if ($this->adjust_partitions == 1) {
				$this->progress(60);
				$sects = trim(Vps::runCommand("fdisk -l -u {$this->device}  | grep sectors$ | sed s#\"^.* \([0-9]*\) sectors$\"#\"\\1\"#g"));
				$t = trim(Vps::runCommand("fdisk -l -u {$this->device} | sed s#\"\*\"#\"\"#g | grep \"^{$this->device}\" | tail -n 1"));
				$p = trim(Vps::runCommand("echo {$t} | awk '{ print $1 }'"));
				$fs = trim(Vps::runCommand("echo {$t} | awk '{ print $5 }'"));
				if (trim(Vps::runCommand("echo \"{$fs}\" | grep \"[A-Z]\"")) != '') {
					$fs = trim(Vps::runCommand("echo {$t} | awk '{ print $6 }'"));
				}
				$pn = trim(Vps::runCommand("echo \"{$p}\" | sed s#\"{$this->device}[p]*\"#\"\"#g"));
				$pt = $pn > 4 ? 'l' : 'p';
				$start = trim(Vps::runCommand("echo {$t} | awk '{ print $2 }'"));
				if ($fs == 83) {
					$this->getLogger()->info("Resizing Last Partition To Use All Free Space (Sect {$sects} P {$p} FS {$fs} PN {$pn} PT {$pt} Start {$start}");
					echo Vps::runCommand("echo -e \"d\n{$pn}\nn\n{$pt}\n{$pn}\n{$start}\n\n\nw\nprint\nq\n\" | fdisk -u {$this->device}");
					echo Vps::runCommand("kpartx {$this->kpartsOpts} -av {$this->device}");
					$pname = trim(Vps::runCommand("ls /dev/mapper/vz-\"{$this->hostname}\"p{$pn} /dev/mapper/vz-{$this->hostname}{$pn} /dev/mapper/\"{$this->hostname}\"p{$pn} /dev/mapper/{$this->hostname}{$pn} 2>/dev/null | cut -d/ -f4 | sed s#\"{$pn}$\"#\"\"#g"));
					echo Vps::runCommand("e2fsck -p -f /dev/mapper/{$pname}{$pn}");
					$resizefs = trim(Vps::runCommand("which resize4fs 2>/dev/null")) != '' ? 'resize4fs' : 'resize2fs';
					echo Vps::runCommand("$resizefs -p /dev/mapper/{$pname}{$pn}");
					@mkdir('/vz/mounts/'.$this->hostname.$pn, 0777, true);
					echo Vps::runCommand("mount /dev/mapper/{$pname}{$pn} /vz/mounts/{$this->hostname}{$pn};");
					echo Vps::runCommand("echo root:{$this->password} | chroot /vz/mounts/{$this->hostname}{$pn} chpasswd || php {$this->base}/vps_kvm_password_manual.php {$this->password} \"/vz/mounts/{$this->hostname}{$pn}\"");
					if (file_exists('/vz/mounts/'.$this->hostname.$pn.'/home/kvm')) {
						echo Vps::runCommand("echo kvm:{$this->password} | chroot /vz/mounts/{$this->hostname}{$pn} chpasswd");
					}
					echo Vps::runCommand("umount /dev/mapper/{$pname}{$pn}");
					echo Vps::runCommand("kpartx {$this->kpartsOpts} -d {$this->device}");
				} else {
					$this->getLogger()->info("Skipping Resizing Last Partition FS is not 83. Space (Sect {$sects} P {$p} FS {$fs} PN {$pn} PT {$pt} Start {$start}");
				}
			}
		}
		$this->progress(80);
	}

    public function installGzImage($source, $device) {
    	$this->getLogger()->info("Copying {$source} Image");
    	$tsize = trim(Vps::runCommand("stat -c%s \"{$source}\""));
    	echo Vps::runCommand("gzip -dc \"/{$source}\"  | dd of={$device} 2>&1");
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

	public function installImage($source, $device) {
		$this->getLogger()->info("Copying Image");
		$tsize = trim(Vps::runCommand("stat -c%s \"{$source}\""));
		echo Vps::runCommand("dd \"if={$source}\" \"of={$device}\" 2>&1");
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
		echo Vps::runCommand("rm -f dd.progress;");
	}
}
