<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class UpdateCpuCommand extends Command {
	public function brief() {
		return "Change the number of CPUs of a Virtual Machine.";
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('cpu')->desc('Number of CPUs/Cores')->optional()->isa('number');
	}

	public function execute($hostname, $cpu) {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		Vps::stopVps($hostname);
		$maxCpu = $cpu > 8 ? $cpu : 8;
    	`virsh dumpxml > {$hostname}.xml;`;
    	$this->getLogger()->debug('Setting CPU limits');
    	echo `sed s#"<\(vcpu.*\)>.*</vcpu>"#"<vcpu placement='static' current='{$cpu}'>{$maxCpu}</vcpu>"#g -i {$hostname}.xml;`;
		echo `virsh define {$hostname}.xml;`;
		Vps::startVps($hostname);
	}
}
