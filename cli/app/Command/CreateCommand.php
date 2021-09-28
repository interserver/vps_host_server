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
    public $cpanel = false;
    public $webuzo = false;

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
		$opts->add('v|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
        $opts->add('m|mac:', 'MAC Address')->isa('string');
        $opts->add('o|order-id:', 'Order ID')->isa('number');
        $opts->add('i|add-ip+', 'Additional IPs')->multiple()->isa('string');
        $opts->add('c|client-ip:', 'Client IP')->isa('ip');
		$opts->add('a|all', 'Use All Available HD, CPU Cores, and 70% RAM');
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
		Vps::init($this->getOptions(), ['hostname' => $hostname, 'ip' => $ip, 'template' => $template, 'hd' => $hd, 'ram' => $ram, 'cpu' => $cpu, 'password' => $password]);
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->writeln("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->writeln("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
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
        $this->useAll = array_key_exists('all', $opts->keys) && $opts->keys['all']->value == 1;
        $this->extraIps = array_key_exists('add-ip', $opts->keys) ? $opts->keys['add-ip']->value : [];
        $this->clientIp = array_key_exists('client-ip', $opts->keys) ? $opts->keys['client-ip']->value : '';
		$this->orderId = array_key_exists('order-id', $opts->keys) ? $opts->keys['order-id']->value : '';
        $this->mac = array_key_exists('mac', $opts->keys) ? $opts->keys['mac']->value : '';
		if ($this->orderId == '')
			$this->orderId = str_replace(['qs', 'windows', 'linux', 'vps'], ['', '', '', ''], $this->hostname); // convert hostname to id
		if ($this->mac == '' && is_numeric($this->orderId))
			$this->mac = Vps::convertIdToMac($this->orderId, $this->useAll); // use id to generate mac address
        $this->url = Vps::getUrl($this->useAll);
        $this->kpartxOpts = preg_match('/sync/', Vps::runCommand("kpartx 2>&1")) ? '-s' : '';
		$this->ram = $this->ram * 1024; // convert ram to kb
		$this->hd = $this->hd * 1024; // convert hd to mb
        if ($this->useAll == true) {
			$this->hd = 'all';
			$this->ram = Vps::getUsableRam();
			$this->cpu = Vps::getCpuCount();
        }
        $this->maxCpu = $this->cpu > 8 ? $this->cpu : 8;
    	$this->maxRam = $this->ram > 16384000 ? $this->ram : 16384000;
    	if (Vps::getVirtType() == 'kvm') {
			$this->pool = Vps::getPoolType();
			$this->device = $this->pool == 'zfs' ? '/vz/'.$this->hostname.'/os.qcow2' : '/dev/vz/'.$this->hostname;
		}
        if (Vps::getVirtType() == 'virtuozzo') {
	        if ($this->template == 'centos-7-x86_64-breadbasket') {
				$this->template = 'centos-7-x86_64';
				$this->webuzo = true;
	        } elseif ($this->template == 'centos-7-x86_64-cpanel') {
				$this->template = 'centos-7-x86_64';
				$this->cpanel = true;
	        }
		}
		$this->progress(5);
		Vps::checkDeps();
		$this->progress(10);
		Vps::setupStorage($this->hostname, $this->device, $this->pool, $this->hd);
		$this->progress(15);
		if ($this->error == 0) {
			if (!Vps::defineVps($this->hostname, $this->template, $this->ip, $this->extraIps, $this->mac, $this->device, $this->pool, $this->ram, $this->cpu, $this->maxRam, $this->maxCpu, $this->useAll, $this->password))
				$this->error++;
			else
			$this->progress(25);
		}
		if ($this->error == 0) {
			if (!Vps::installTemplate($this->hostname, $this->template, $this->password, $this->device, $this->pool, $this->hd, $this->kpartxOpts))
				$this->error++;
			else
				$this->progress(70);
		}
		if ($this->error == 0) {
			$this->getLogger()->info('Enabling and Starting up the VPS');
			Vps::enableAutostart($this->hostname);
			Vps::startVps($this->hostname);
			$this->progress(85);
		}
		if ($this->error == 0) {
			if ($this->webuzo === true)
				Vps::setupWebuzo($this->hostname);
			if ($this->cpanel === true)
				Vps::setupCpanel($this->hostname);
			Vps::setupCgroups($this->hostname, $this->useAll, $this->cpu);
			$this->progress(90);
			Vps::setupRouting($this->hostname, $this->ip, $this->pool, $this->useAll);
			$this->progress(95);
			Vps::setupVnc($this->hostname, $this->clientIp);
			Vps::vncScreenshot($this->hostname, $this->url);
			$this->progress(100);
		}
	}

    public function progress($progress) {
    	$progress = escapeshellarg($progress);
    	Vps::runCommand("curl --connect-timeout 10 --max-time 20 -k -d action=install_progress -d progress={$progress} -d server={$this->orderId} '{$this->url}' < /dev/null > /dev/null 2>&1;");
		$this->getLogger()->writeln($progress.'%');
    }
}
