<?php
namespace App\Command\InternalsCommand\{$className}Command;

use {$classFullName};
use CLIFramework\Command;
use CLIFramework\Component\Table\Table;
use CLIFramework\Component\Table\MarkdownTableStyle;

/**
* {$summary}
*/
class {$pascalCase}Command extends Command
{
	public function brief() {
		return "{$summary}";
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
		$response = {$className}::{$methodName}();
		if ($json == true) {
			$this->getLogger()->write(json_encode($response, JSON_PRETTY_PRINT));
		} elseif ($php == true) {
			$this->getLogger()->write(var_export($response, true));
		} else {
{if $type == 'array'}
			if (count($response) == 0) {
				$this->getLogger()->error('This machine does not appear to have any virtualization setup installed.');
				return 1;
			}
			$table = new Table;
			$table->setStyle(new MarkdownTableStyle());
			$table->setHeaders(['vps']);
			$formatter = new Formatter;
			foreach ($response as $line)
				$table->addRow([$line]);
			echo $table->render();
{elseif $type == 'bool'}
			echo ($response === true ? 'true' : 'false').PHP_EOL;
{else}
			echo $response.PHP_EOL;
{/if}
		}
	}
}
