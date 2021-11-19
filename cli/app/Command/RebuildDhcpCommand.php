<?php
namespace App\Command;

use App\Vps;
use App\Os\Dhcpd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class RebuildDhcpCommand extends Command {
	public function brief() {
		return "Regenerates the dhcpd config and host assignments files.\n\n	<what> can be 'conf', 'vps', or 'all' to regenerate the config file, host assignmetns file, or both (respectivly)";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
		$opts->add('a|all', 'QS host using all resources in a single vps');
		$opts->add('o|output', 'Output the file contents instead of writing it');
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('what')->desc('rebuild the dhcpd.conf config (conf), dhcpd.vps host asignments (vps), or both (all)')->validValues(['conf','vps','all']);
	}

	public function execute($what = '') {
		$useAll = false;
		Vps::init($this->getOptions(), ['what' => $what]);
		if (!Vps::isVirtualHost()) {
			Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!in_array($what, ['conf', 'vps', 'all'])) {
			Vps::getLogger()->error("Invalid or missing <what> value");
			Vps::getLogger()->error("<what> can be 'conf', 'vps', or 'all' to regenerate the config file, host assignmetns file, or both (respectivly)");
			return 1;
		}
		/** @var {\GetOptionKit\OptionResult|GetOptionKit\OptionCollection} */
		$opts = $this->getOptions();
		$useAll = array_key_exists('all', $opts->keys) && $opts->keys['all']->value == 1;
		$output = array_key_exists('output', $opts->keys) && $opts->keys['output']->value == 1;
		if (in_array($what, ['conf', 'all']))
			Dhcpd::rebuildConf($useAll, $output);
		if (in_array($what, ['vps', 'all']))
			Dhcpd::rebuildHosts($useAll, $output);
	}
}
