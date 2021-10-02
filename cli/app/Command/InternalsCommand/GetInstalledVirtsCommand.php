<?php
namespace App\Command\InternalsCommand;

use App\Vps;
use App\Os\Os;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;
use CLIFramework\Component\Progress\ProgressBar;

class GetInstalledVirtsCommand extends Command {
	public function brief() {
		return "returns an array of installed virtualization types";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('j|json', 'output in JSON format');
	}

	public function execute() {
		Vps::init($this->getOptions(), []);
	}
}
