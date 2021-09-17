<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class ChangeTimezoneCommand extends Command {
	public function brief() {
		return "ChangeTimezones a Virtual Machine.";
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
		$this->changeTimezoneVps($hostname);
	}

/*
export PATH="$PATH:/usr/sbin:/sbin:/bin:/usr/bin:";
rm -f /etc/xinetd.d/{$hostname};
service xinetd restart 2>/dev/null || /etc/init.d/xinetd restart 2>/dev/null;
virsh destroy {$hostname};
virsh dumpxml {$hostname} > {$hostname}.xml
sed s#"<clock.*$"#"<clock offset='timezone' timezone='{$param}'/>"#g -i {$hostname}.xml;
virsh define {$hostname}.xml;
rm -f {$hostname}.xml;
virsh start {$hostname};
bash /root/cpaneldirect/run_buildebtables.sh;

*/
}
