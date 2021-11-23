<?php
namespace App\Command\CronCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class VirtuozzoUpdateCommand extends Command {
	public function brief() {
		return "lists the history entries";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
	}

	public function execute() {
		Vps::init($this->getOptions(), []);
		if (trim(Vps::runCommand('which vzpkg')) == '') {
			mail('support@interserver.net', 'Cannot find vzpkg package for "provirted.phar cron virtuozzo-update" on '.gethostname(), 'Cannot find vzpkg package for update_virtuozzo.sh script on '.gethostname());
		} else {
			passthru('vzpkg update metadata');
			passthru('vzpkg list -O | awk \'{ print $1 }\' | xargs -n 1 vzpkg fetch -O');
			if ((time() - intval(filemtime(Vps::$base.'/.cron_weekly.age'))) > 604800) { // if older than a week
				passthru('vzpkg update cache --update-cache');
				touch(Vps::$base.'/.cron_weekly.age');
			}
		}
	}
}
