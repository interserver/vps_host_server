<?php
namespace App\Command;

use App\Vps;
use App\Os\Os;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;
use CLIFramework\Component\Progress\ProgressBar;

class CreateCommand extends Command {
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
    	$progName = basename($this->getApplication()->getProgramName());
        return <<<HELP
<bold>EXAMPLES</bold>
	{$progName} create vps1001 vps2.provirted.com 192.168.1.103 centos-7 25 2048 1 password
	{$progName} create --virt=virtuozzo vps1002 vps3.provirted.com 192.168.1.104 ubuntu-20.04 25 2048 1 password
	{$progName} create -vv --order-id=2328714 --add-ip=192.168.1.101 --add-ip=192.168.1.102 --client-ip=127.0.0.1 vps1003 vps3.provirted.com 192.168.1.105 ubuntu-20.04 60 4096 2 password

<underline>underlined text</underline>
HELP;
    }

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
        $opts->add('m|mac:', 'MAC Address')->isa('string');
        $opts->add('o|order-id:', 'Order ID')->isa('number');
        $opts->add('i|add-ip+', 'Additional IPs')->multiple()->isa('string');
        $opts->add('c|client-ip:', 'Client IP')->isa('ip');
		$opts->add('a|all', 'Use All Available HD, CPU Cores, and 70% RAM');
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('vzid')->desc('VPS id/name to use')->isa('string');
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('ip')->desc('IP Address')->isa('ip');
		$args->add('template')->desc('Install Image To Use')->isa('string');
		$args->add('hd')->desc('HD Size in GB')->optional()->isa('number');
		$args->add('ram')->desc('Ram In MB')->optional()->isa('number');
		$args->add('cpu')->desc('Number of CPUs/Cores')->optional()->isa('number');
		$args->add('password')->desc('Root/Administrator password')->optional()->isa('string');
	}

	public function execute($vzid, $hostname, $ip, $template, $hd = 25, $ram = 1024, $cpu = 1, $password = '') {
		Vps::init($this->getOptions(), ['vzid' => $vzid, 'hostname' => $hostname, 'ip' => $ip, 'template' => $template, 'hd' => $hd, 'ram' => $ram, 'cpu' => $cpu, 'password' => $password]);
		if (!Vps::isVirtualHost()) {
			Vps::getLogger()->writeln("This machine does not appear to have any virtualization setup installed.");
			Vps::getLogger()->writeln("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		/** @var {\GetOptionKit\OptionResult|GetOptionKit\OptionCollection} */
		$opts = $this->getOptions();
		Vps::getLogger()->info('Initializing Variables and process Options and Arguments');
		$error = 0;
        $useAll = array_key_exists('all', $opts->keys) && $opts->keys['all']->value == 1;
        $extraIps = array_key_exists('add-ip', $opts->keys) ? $opts->keys['add-ip']->value : [];
        $clientIp = array_key_exists('client-ip', $opts->keys) ? $opts->keys['client-ip']->value : '';
		$orderId = array_key_exists('order-id', $opts->keys) ? $opts->keys['order-id']->value : '';
        $mac = array_key_exists('mac', $opts->keys) ? $opts->keys['mac']->value : '';
		if ($orderId == '')
			$orderId = str_replace(['qs', 'windows', 'linux', 'vps'], ['', '', '', ''], $vzid); // convert hostname to id
		if ($mac == '' && is_numeric($orderId))
			$mac = Vps::convertIdToMac($orderId, $useAll); // use id to generate mac address
        $url = Vps::getUrl($useAll);
        $kpartxOpts = preg_match('/sync/', Vps::runCommand("kpartx 2>&1")) ? '-s' : '';
		$ram = $ram * 1024; // convert ram to kb
		$hd = $hd * 1024; // convert hd to mb
		$device = '';
		$pool = '';
        if ($useAll == true) {
			$hd = 'all';
			$ram = Os::getUsableRam();
			$cpu = Os::getCpuCount();
        }
        $maxCpu = $cpu > 8 ? $cpu : 8;
    	$maxRam = $ram > 16384000 ? $ram : 16384000;
    	if (Vps::getVirtType() == 'kvm') {
			$pool = Vps::getPoolType();
			$device = $pool == 'zfs' ? '/vz/'.$vzid.'/os.qcow2' : '/dev/vz/'.$vzid;
		}
		$webuzo = false;
		$cpanel = false;
        if (Vps::getVirtType() == 'virtuozzo') {
	        if ($template == 'centos-7-x86_64-breadbasket') {
				$template = 'centos-7-x86_64';
				$webuzo = true;
	        } elseif ($template == 'centos-7-x86_64-cpanel') {
				$template = 'centos-7-x86_64';
				$cpanel = true;
	        }
		}
		$this->progress(5, $url, $orderId);
		Os::checkDeps();
		$this->progress(10, $url, $orderId);
		Vps::setupStorage($vzid, $device, $pool, $hd);
		$this->progress(15, $url, $orderId);
		if ($error == 0) {
			if (!Vps::defineVps($vzid, $hostname, $template, $ip, $extraIps, $mac, $device, $pool, $ram, $cpu, $hd, $maxRam, $maxCpu, $useAll, $password))
				$error++;
			else
			$this->progress(25, $url, $orderId);
		}
		if ($error == 0) {
			if (!Vps::installTemplate($vzid, $template, $password, $device, $pool, $hd, $kpartxOpts))
				$error++;
			else
				$this->progress(70, $url, $orderId);
			if (Vps::getVirtType() == 'kvm') {
				$password = escapeshellarg($password);
				$hostname = escapeshellarg($hostname);
				Vps::getLogger()->write(Vps::runCommand("virt-customize -d {$vzid} --root-password password:{$password} --hostname {$hostname};"));
			}
		}
		if ($error == 0) {
			Vps::getLogger()->info('Enabling and Starting up the VPS');
			Vps::enableAutostart($vzid);
			Vps::startVps($vzid);
			$this->progress(85, $url, $orderId);
		}
		if ($error == 0) {
			if ($webuzo === true)
				Vps::setupWebuzo($vzid);
			if ($cpanel === true)
				Vps::setupCpanel($vzid);
			Vps::setupCgroups($vzid, $useAll, $cpu);
			$this->progress(90, $url, $orderId);
			Vps::setupRouting($vzid, $ip, $pool, $useAll, $orderId);
			$this->progress(95, $url, $orderId);
			Vps::setupVnc($vzid, $clientIp);
			Vps::vncScreenshot($vzid, $url);
			$this->progress(100, $url, $orderId);
		}
	}

    public function progress($progress, $url, $orderId) {
    	$progress = escapeshellarg($progress);
    	Vps::runCommand("curl --connect-timeout 10 --max-time 20 -k -d action=install_progress -d progress={$progress} -d server={$orderId} '{$url}' < /dev/null > /dev/null 2>&1;");
		Vps::getLogger()->writeln($progress.'%');
    }
}
