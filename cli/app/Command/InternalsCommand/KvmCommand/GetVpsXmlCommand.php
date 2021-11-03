<?php
namespace App\Command\InternalsCommand\KvmCommand;

use \App\Vps\Kvm;
use CLIFramework\Command;
use CLIFramework\Component\Table\Table;
use CLIFramework\Component\Table\MarkdownTableStyle;

/**
* gets the vps details in xml format
*/
class GetVpsXmlCommand extends Command
{
	public function brief() {
		return "gets the vps details in xml format";
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
		$response = Kvm::getVpsXml();
		if ($json == true) {
			$this->getLogger()->write(json_encode($response, JSON_PRETTY_PRINT));
		} elseif ($php == true) {
			$this->getLogger()->write(var_export($response, true));
		} else {
			echo $response.PHP_EOL;
		}
	}
}
