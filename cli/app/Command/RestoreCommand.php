<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class RestoreCommand extends Command {
	public function brief() {
		return "Restores a Virtual Machine.";
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
		if (!Vps::isVpsRunning($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to be powered on.");
			return 1;
		}
		$this->restoreVps($hostname);
	}

/*
/root/cpaneldirect/vps_swift_restore.sh {$param1} {$param2} {$hostname} && \
curl --connect-timeout 60 --max-time 600 -k -d action=restore_status -d vps_id={$vps_id} https://{$domain}/vps_queue.php || \
curl --connect-timeout 60 --max-time 600 -k -d action=restore_status -d vps_id={$vps_id} https://{$domain}/vps_queue.php;

*/
}
