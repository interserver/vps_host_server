<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class UpdateRamCommand extends Command {
	public function brief() {
		return "Change the memory size of a Virtual Machine.";
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('ram')->desc('Ram Size in MB')->optional()->isa('number');
	}

	public function execute($hostname, $ram) {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		$ram = $ram * 1024;
		Vps::stopVps($hostname);
    	$maxRam = $ram > 16384000 ? $ram : 16384000;
    	`virsh dumpxml > {$hostname}.xml;`;
    	$this->getLogger()->debug('Setting Max Memory limits');
		echo `sed s#"<memory.*memory>"#"<memory unit='KiB'>{$maxRam}</memory>"#g -i {$hostname}.xml;`;
		$this->getLogger()->debug('Setting Memory limits');
		echo `sed s#"<currentMemory.*currentMemory>"#"<currentMemory unit='KiB'>{$ram}</currentMemory>"#g -i {$hostname}.xml;`;
		echo `virsh define {$hostname}.xml;`;
		echo `rm -f {$hostname}.xml`;
		Vps::startVps($hostname);
	}
}
