<?php
namespace App\Command;

use CLIFramework\Command;

class HistoryCommand extends Command {
	public function brief() {
		return "Display previously run commands and get detailed information on the output and commands run";
	}

	public function execute() {
        echo '
SYNTAX

provirted.phar history <subcommand>

SUBCOMMANDS
	list                      lists the history entries
	show <id>                 displays one of the history entries, -1 is the always the latest entry
';
	}
}
