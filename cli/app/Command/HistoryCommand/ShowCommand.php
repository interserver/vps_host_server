<?php
namespace App\Command\HistoryCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class ShowCommand extends Command {
	public function brief() {
		return "displays one of the history entries, -1 is the always the latest entry";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('id')->desc('History id to use, -1 is always the latest entry')->isa('number')->validValues([Vps::class, 'getHistoryChoices']);
	}

	public function execute($id) {
		Vps::init($this->getOptions(), ['id' => $id]);
        $allHistory = file_exists($_SERVER['HOME'].'/.provirted/history.json') ? json_decode(file_get_contents($_SERVER['HOME'].'/.provirted/history.json'), true) : [];
        $id = intval($id);
        if ($id < 0)
        	$id = count($allHistory) + $id;
        if (!array_key_exists($id, $allHistory)) {
			echo 'Invalid ID';
			return;
        }
        $data = $allHistory[$id];
        print_r($data);
	}
}
