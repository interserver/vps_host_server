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

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('timezone')->desc('The Timezone, ie America/New_York')->isa('string');
	}

	public function execute($hostname, $timezone) {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if (!Vps::isVpsRunning($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to be powered on.");
			return 1;
		}
		Vps::stopVps($hostname);
		echo Vps::runCommand("virsh dumpxml {$hostname} > {$hostname}.xml");
		echo Vps::runCommand("sed s#\"<clock.*$\"#\"<clock offset='timezone' timezone='{$timezone}'/>\"#g -i {$hostname}.xml");
		echo Vps::runCommand("virsh define {$hostname}.xml");
		echo Vps::runCommand("rm -f {$hostname}.xml");
		Vps::startVps($hostname);
	}
}
