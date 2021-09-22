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
		return "Enable the CD-ROM and optionally Insert a CD in a Virtual Machine.";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('url')->desc('CD image URL')->isa('string');
	}

	public function execute($hostname, $url = '') {
		Vps::init($this->getArgInfoList(), func_get_args(), $this->getOptions());
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if (trim(Vps::runCommand("virsh dumpxml {$hostname}|grep \"disk.*cdrom\"")) != "") {
			$this->getLogger()->error("Skipping Setup, CD-ROM Drive already exists in VPS configuration");
		} else {
			if ($url == '') {
				echo Vps::runCommand("virsh attach-disk {$hostname} - hda --targetbus ide --type cdrom --sourcetype file --config");
				echo Vps::runCommand("virsh change-media {$hostname} hda --eject --config");
			} else {
				echo Vps::runCommand("virsh attach-disk {$hostname} \"{$url}\" hda --targetbus ide --type cdrom --sourcetype file --config");
			}
			Vps::restartVps($hostname);

		}
	}

}
