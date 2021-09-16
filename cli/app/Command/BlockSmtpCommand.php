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
		return "BlockSmtps a Virtual Machine.";
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
		$this->blockSmtpVps($hostname);
	}

/*
export PATH="$PATH:/usr/sbin:/sbin:/bin:/usr/bin:";
/admin/kvmenable blocksmtp {$vps_id};
*/

	public function blockSmtpVps($hostname) {
		$this->getLogger()->info('BlockSmtpping the VPS');
		$this->getLogger()->indent();
		$this->getLogger()->info('Sending Softwawre Power-Off');
		echo `/usr/bin/virsh shutdown {$hostname}`;
		$blockSmtpped = false;
		$waited = 0;
		$maxWait = 120;
		$sleepTime = 10;
		$continue = true;
		while ($waited <= $maxWait && $blockSmtpped == false) {
			if (Vps::isVpsRunning($hostname)) {
				$this->getLogger()->info('still running, waiting (waited '.$waited.'/'.$maxWait.' seconds)');
				sleep($sleepTime);
				$waited += $sleepTime;
			} else {
				$this->getLogger()->info('appears to have cleanly shutdown');
				$blockSmtpped = true;
			}
		}
		if ($blockSmtpped === false) {
			$this->getLogger()->info('Sending Hardware Power-Off');
			echo `/usr/bin/virsh destroy {$hostname};`;
		}
		$this->getLogger()->unIndent();
	}
}
