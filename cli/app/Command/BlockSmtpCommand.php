<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class BlockSmtpCommand extends Command {
	public function brief() {
		return "Blocks SMTP on a Virtual Machine.";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('vzid')->desc('VPS id/name to use')->isa('string');
		$args->add('id')->desc('VPS ID')->isa('number');
	}

	public function execute($vzid, $id = '') {
		Vps::init($this->getOptions(), ['vzid' => $vzid, 'id' => $id]);
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($vzid)) {
			$this->getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if ($id == '')
			$id = str_replace(['qs', 'windows', 'linux', 'vps'], ['', '', '', ''], $vzid);
		if (!is_numeric($id)) {
			$this->getLogger()->error("Either no ID was passed and we could not guess the ID from the Hostname, or a nonn-numeric ID was passed.");
			return 1;
		}
		Vps::blockSmtp($vzid, $id);
	}

/*
;
*/
}
