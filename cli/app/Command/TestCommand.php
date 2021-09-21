<?php
namespace App\Command;

use App\Vps;
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
		$logAction->setStatus('setup');
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		$logAction->setStatus('exists');
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		$logAction->setStatus('running');
		if (!Vps::isVpsRunning($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified appears to be already running.");
			return 1;
		}
		$logAction->done();
		$logAction = $logger->newAction('DHCP');
		$logAction->setStatus('configured');
		$hosts = Vps::getDhcpHosts();
		$mac = Vps::getVpsMac($hostname);
		$ips = Vps::getVpsIps($hostname);
		$logAction->setStatus('running');
		if (!Vps::isDhcpRunning()) {
			$this->getLogger()->error("XinetD does not appear to be running.");
			return 1;
		}
		$logAction->done();
		$logAction = $logger->newAction('XinetD');
		$logAction->setStatus('configured');
		$logAction->setStatus('running');
		if (!Vps::isXinetdRunning()) {
			$this->getLogger()->error("XinetD does not appear to be running.");
			return 1;
		}
		$logAction->done();
		$logAction = $logger->newAction('Networking');
		$logAction->setStatus('request');
		$logAction->setStatus('pinging');
		$logAction->done();
		$logAction = $logger->newAction('SSH');
		$logAction->setStatus('connect');
		$logAction->setStatus('authentication');
	    $logAction->done();
	}
}
