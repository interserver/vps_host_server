<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class EnableCdCommand extends Command {
	public function brief() {
		return "Enable the CD-ROM in a Virtual Machine.";
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('url')->desc('CD image URL')->isa('string');
	}

	public function execute($hostname, $url = '') {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if (trim(`virsh dumpxml {$hostname}|grep "disk.*cdrom"`) != "") {
			$this->getLogger()->error("Skipping Setup, CD-ROM Drive already exists in VPS configuration");
		} else {
			if ($url == '') {
				echo `virsh attach-disk {$hostname} - hda --targetbus ide --type cdrom --sourcetype file --config`;
				echo `virsh change-media {$hostname} hda --eject --config`;
			} else {
				echo `virsh attach-disk {$hostname} "{$url}" hda --targetbus ide --type cdrom --sourcetype file --config`;
			}
			Vps::restartVps($hostname);

		}
	}

}
