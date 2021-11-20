<?php
namespace App\Command\VncCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class SetupCommand extends Command {
	public function brief() {
		return "Setup VNC Allowed IP on a Virtual Machine.";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('vzid')->desc('VPS id/name to use')->isa('string')->validValues([Vps::class, 'getAllVpsAllVirts']);
		$args->add('ip')->desc('IP Address')->isa('ip');
	}

	public function execute($vzid, $ip = '') {
		Vps::init($this->getOptions(), ['vzid' => $vzid, 'ip' => $ip]);
		if (!Vps::isVirtualHost()) {
			Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($vzid)) {
			Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		Xinetd::lock();
        $remotes = Vps::getVpsRemotes($vzid);
        if (Vps::getVirtType() == 'virtuozzo') {
        	$vps = Virtuozzo::getVps($vzid);
        	$vzid = $vps['EnvID'];
		}
        Vps::getLogger()->write('Parsing Services...');
		$services = Xinetd::parseEntries();
		Vps::getLogger()->write('done'.PHP_EOL);
		foreach ($services as $serviceName => $serviceData) {
			if (in_array($serviceName, [$vzid, $vzid.'-spice'])
				|| (isset($serviceData['port']) && in_array(intval($serviceData['port']), array_values($remotes)))) {
				Vps::getLogger()->write("removing {$serviceData['filename']}\n");
				unlink($serviceData['filename']);
			}
		}
		foreach ($remotes as $type => $port) {
			Vps::getLogger()->write("setting up {$type} on {$vzid} port {$port}".(trim($ip) != '' ? " ip {$ip}" : "")."\n");
			Xinetd::setup($type == 'vnc' ? $vzid : $vzid.'-'.$type, $port, trim($ip) != '' ? $ip : false);
		}
		Xinetd::unlock();
		Xinetd::restart();
	}
}
