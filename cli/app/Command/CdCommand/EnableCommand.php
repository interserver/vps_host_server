<?php
namespace App\Command\CdCommand;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class EnableCommand extends Command {
	public function brief() {
		return "Enable the CD-ROM and optionally Insert a CD in a Virtual Machine.";
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
		$args->add('url')->desc('CD image URL')->isa('string');
	}

	public function execute($vzid, $url = '') {
		Vps::init($this->getOptions(), ['vzid' => $vzid, 'url' => $url]);
		if (!Vps::isVirtualHost()) {
			Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($vzid)) {
			Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if (trim(Vps::runCommand("virsh dumpxml {$vzid}|grep \"disk.*cdrom\"")) != "") {
			Vps::getLogger()->error("Skipping Setup, CD-ROM Drive already exists in VPS configuration");
		} else {
			if ($url == '') {
				Vps::getLogger()->write(Vps::runCommand("virsh attach-disk {$vzid} - hda --targetbus ide --type cdrom --sourcetype file --config"));
				Vps::getLogger()->write(Vps::runCommand("virsh change-media {$vzid} hda --eject --config"));
			} else {
				Vps::getLogger()->write(Vps::runCommand("virsh attach-disk {$vzid} \"{$url}\" hda --targetbus ide --type cdrom --sourcetype file --config"));
			}
			Vps::restartVps($vzid);

		}
	}

}
