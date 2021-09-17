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
		return "Restores a Virtual Machine from Backup.";
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('source')->desc('Source Backup Hostname to use')->isa('string');
		$args->add('name')->desc('Backup Name to restore')->isa('string');
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('id')->desc('VPS ID')->isa('number');
	}

	public function execute($source, $name, $hostname, $id) {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		echo `/root/cpaneldirect/vps_swift_restore.sh {$source} {$name} {$hostname} && curl --connect-timeout 60 --max-time 600 -k -d action=restore_status -d vps_id={$id} https://myvps.interserver.net/vps_queue.php || curl --connect-timeout 60 --max-time 600 -k -d action=restore_status -d vps_id={$id} https://myvps.interserver.net/vps_queue.php`;
	}
}
