<?php
namespace App\Command;

use App\Vps;
use App\Os\Os;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;
use CLIFramework\Component\Progress\ProgressBar;

class ApiCommand extends Command {
	public function brief() {
		return "Run internal api calls";
	}
}
