<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class ChangeTimezoneCommand extends Command {
	public function brief() {
		return "Change the Timezone of a Virtual Machine.";
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
		$args->add('timezone')->desc('The Timezone, ie America/New_York')->isa('string');
	}

	public function execute($vzid, $timezone) {
		Vps::init($this->getOptions(), ['vzid' => $vzid, 'timezone' => $timezone]);
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($vzid)) {
			$this->getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if (!Vps::isVpsRunning($vzid)) {
			$this->getLogger()->error("The VPS '{$vzid}' you specified does not appear to be powered on.");
			return 1;
		}
		Vps::stopVps($vzid);
		Vps::getLogger()->write(Vps::runCommand("virsh dumpxml {$vzid} > {$vzid}.xml"));
		Vps::getLogger()->write(Vps::runCommand("sed s#\"<clock.*$\"#\"<clock offset='timezone' timezone='{$timezone}'/>\"#g -i {$vzid}.xml"));
		Vps::getLogger()->write(Vps::runCommand("virsh define {$vzid}.xml"));
		Vps::getLogger()->write(Vps::runCommand("rm -f {$vzid}.xml"));
		Vps::startVps($vzid);
	}
}
