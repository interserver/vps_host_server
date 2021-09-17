<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class DisableCdCommand extends Command {
	public function brief() {
		return "DisableCds a Virtual Machine.";
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
	}

	public function execute($hostname) {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if (trim(`virsh dumpxml {$hostname}|grep "disk.*cdrom"`) == "") {
			$this->getLogger()->error("Skipping Removal, No CD-ROM Drive exists in VPS configuration");
		} else {
			echo `virsh detach-disk {$hostname} hda --config`;
			Vps::restartVps($hostname);
			echo `/root/cpaneldirect/vps_refresh_vnc.sh {$hostname}`;
		}
	}

}
