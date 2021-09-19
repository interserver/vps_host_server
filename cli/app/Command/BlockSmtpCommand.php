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

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('id')->desc('VPS ID')->isa('number');
	}

	public function execute($hostname, $id = '') {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if ($id == '')
			$id = str_replace(['qs', 'windows', 'linux', 'vps'], ['', '', '', ''], $this->hostname);
		if (!is_numeric($id)) {
			$this->getLogger()->error("Either no ID was passed and we could not guess the ID from the Hostname, or a nonn-numeric ID was passed.");
			return 1;
		}
		echo `/admin/kvmenable blocksmtp {$id}`;
	}

/*
;
*/
}
