<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class InstallCpanelCommand extends Command {
	public function brief() {
		return "Runs the CPanel Installation on a Virtual Machine.";
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
		$args->add('email')->desc('Email Address')->isa('string');
	}

	public function execute($vzid, $email) {
		Vps::init($this->getOptions(), ['vzid' => $vzid, 'email' => $email]);
		if (!Vps::isVirtualHost()) {
			Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($vzid)) {
			Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if (Vps::getVirtType() == 'virtuozzo') {
			$email = escapeshellarg($email);
			Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'if [ ! -e /usr/bin/screen ]; then yum -y install screen; fi'"));
			Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} 'if [ ! -e /admin/cpanelinstall ]; then rsync -a rsync://mirror.trouble-free.net/admin /admin; fi'"));
			Vps::getLogger()->write(Vps::runCommand("prlctl exec {$vzid} '/admin/cpanelinstall {$email};'"));
		} elseif (Vps::getVirtType() == 'openvz') {
			$email = escapeshellarg($email);
			Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'if [ ! -e /usr/bin/screen ]; then yum -y install screen; fi'"));
			Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} 'if [ ! -e /admin/cpanelinstall ]; then rsync -a rsync://mirror.trouble-free.net/admin /admin; fi'"));
			Vps::getLogger()->write(Vps::runCommand("vzctl exec {$vzid} '/admin/cpanelinstall {$email};'"));
		}
	}
}
