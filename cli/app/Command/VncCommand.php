<?php
namespace App\Command;

use CLIFramework\Command;

class VncCommand extends Command {
	public function brief() {
		return "Prepairs and works with a source based version of the project (instead of the phar)";
	}

	public function execute() {
        echo '
SYNTAX

provirted.phar vnc <subcommand>

SUBCOMMANDS
	secure [--dry]            removes old and bad entries to maintain security
	setup <vzid> [ip]         create a new mapping
	remove <vzid>             remove a mapping
	restart                   restart the xinetd service
	rebuild [--dry]           removes old and bad entries to maintain security, and recreates all port mappings

EXAMPLES
	provirted.phar vnc setup vps4000 8.8.8.8
	provirted.phar vnc remove vps4000
	provirted.phar vnc secure
	provirted.phar vnc restart
	provirted.phar vnc rebuild --dry
	provirted.phar vnc rebuild
';
	}
}
