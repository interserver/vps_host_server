<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class AddIpCommand extends Command {
	public function brief() {
		return "Adds an IP Address to a Virtual Machine.";
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('ip')->desc('IP Address')->isa('ip');
	}

	public function execute($hostname, $ip) {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		echo `virsh dumpxml --inactive --security-info {$hostname} > {$hostname}.xml`;
		echo `sed s#"</filterref>"#"  <parameter name='IP' value='{$ip}'/>\\n    </filterref>"#g -i {$hostname}.xml`;
		echo `/usr/bin/virsh define {$hostname}.xml`;
		echo `rm -f {$hostname}.xml`;
	}
}
