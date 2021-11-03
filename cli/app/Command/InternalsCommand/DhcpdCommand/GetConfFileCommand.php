<?php
namespace App\Command\InternalsCommand\DhcpdCommand;

use \App\Os\Dhcpd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Component\Table\Table;
use CLIFramework\Component\Table\MarkdownTableStyle;

/**
* returns the name of the dhcpd config file
*/
class GetConfFileCommand extends Command
{
	public function brief() {
		return "returns the name of the dhcpd config file";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('j|json', 'output in JSON format');
		$opts->add('p|php', 'output in PHP format');
	}

	public function execute() {
		$opts = $this->getOptions();
		$json = array_key_exists('json', $opts->keys) && $opts->keys['json']->value == 1;
		$php = array_key_exists('php', $opts->keys) && $opts->keys['php']->value == 1;
		$response = Dhcpd::getConfFile();
		if ($json == true) {
			$this->getLogger()->write(json_encode($response, JSON_PRETTY_PRINT));
		} elseif ($php == true) {
			$this->getLogger()->write(var_export($response, true));
		} else {
			echo $response.PHP_EOL;
		}
	}
}
