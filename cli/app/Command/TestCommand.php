<?php
namespace App\Command;

use App\Vps;
use App\Os\Dhcpd;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;

class TestCommand extends Command {
	public function brief() {
		return "Perform various self diagnostics to check on the health and prepairedness of the system.";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('vzid')->desc('VPS id/name to use')->isa('string');
	}

	public function execute($vzid) {
		$this->getLogger()->writeln('Running Tests on '.$vzid);
		$this->getLogger()->newline();
		//$logger = new ActionLogger(fopen('php://stderr','w'), new Formatter);
		$logger = new ActionLogger(fopen('php://stdout','w'), new Formatter);
		$logAction = $logger->newAction('VPS');
		$logAction->setStatus('setup');
		Vps::init($this->getOptions(), ['vzid' => $vzid]);
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		$logAction->setStatus('exists');
		if (!Vps::vpsExists($vzid)) {
			$this->getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		$logAction->setStatus('running');
		if (!Vps::isVpsRunning($vzid)) {
			$this->getLogger()->error("The VPS '{$vzid}' you specified appears to be already running.");
			return 1;
		}
		$logAction->done();
		$logAction = $logger->newAction('DHCP');
		$logAction->setStatus('configured');
		$hosts = Dhcpd::getHosts();
		$mac = Vps::getVpsMac($vzid);
		$ips = Vps::getVpsIps($vzid);
		$logAction->setStatus('running');
		if (!Dhcpd::isRunning()) {
			$this->getLogger()->error("Dhcpd does not appear to be running.");
			return 1;
		}
		$logAction->done();
		$logAction = $logger->newAction('XinetD');
		$logAction->setStatus('configured');
		$logAction->setStatus('running');
		if (!Xinetd::isRunning()) {
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
