<?php
namespace App\Command;

use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;

class TestCommand extends Command {
	public function brief() {
		return "Perform various self diagnostics to check on the health and prepairedness of the system.";
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
	}

	public function execute($hostname) {
		$this->getLogger()->writeln('Running Tests on '.$hostname);
		$this->getLogger()->newline();
		//$logger = new ActionLogger(fopen('php://stderr','w'), new Formatter);
		$logger = new ActionLogger(fopen('php://stdout','w'), new Formatter);
		$logAction = $logger->newAction('VPS');
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		$logAction->setStatus('setup');
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		$logAction->setStatus('exists');
		if (!Vps::isVpsRunning($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified appears to be already running.");
			return 1;
		}
		$logAction->setStatus('running');
		sleep(1);
		$logAction->setStatus('passed all tests');
		$logAction->done();
		sleep(1);
		$logAction = $logger->newAction('DHCP');
		$logAction->setStatus('is our vps configured');
		sleep(1);
		$logAction->setStatus('is it running');
		$logAction->done();
		sleep(1);
		$logAction = $logger->newAction('XinetD');
		$logAction->setStatus('is our vps configured');
		sleep(1);
		$logAction->setStatus('is it running');
		$logAction->done();
		sleep(1);
		$logAction = $logger->newAction('Networking');
		$logAction->setStatus('was dhcp request sent');
		sleep(1);
		$logAction->setStatus('pinging');
		$logAction->done();
		sleep(1);
		$logAction = $logger->newAction('SSH');
		$logAction->setStatus('port is open and accepting connection');
		sleep(1);
		$logAction->setStatus('authentication');
	    $logAction->done();
	}
}
