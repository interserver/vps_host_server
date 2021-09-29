<?php
namespace App\Command;

use CLIFramework\Command;

class SourceCommand extends Command {
	public function brief() {
		return "Prepairs and works with a source based version of the project (instead of the phar)";
	}

	public function execute() {
	}
}
