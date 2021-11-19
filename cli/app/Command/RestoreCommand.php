<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class RestoreCommand extends Command {
	public function brief() {
		return "Restores a Virtual Machine from Backup.";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('source')->desc('Source Backup Hostname to use')->isa('string');
		$args->add('name')->desc('Backup Name to restore')->isa('string');
		$args->add('vzid')->desc('VPS id/name to use')->isa('string')->validValues([Vps::class, 'getAllVpsAllVirts']);
		$args->add('id')->desc('VPS ID')->isa('number');
	}

	public function execute($source, $name, $vzid, $id) {
		Vps::init($this->getOptions(), ['source' => $source, 'name' => $name, 'vzid' => $vzid, 'id' => $id]);
		if (!Vps::isVirtualHost()) {
			Vps::getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			Vps::getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($vzid)) {
			Vps::getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		$base = Vps::$base;
		Vps::getLogger()->write(Vps::runCommand("{$base}/vps_swift_restore.sh {$source} {$name} {$vzid} && curl --connect-timeout 60 --max-time 600 -k -d action=restore_status -d vps_id={$id} https://myvps.interserver.net/vps_queue.php || curl --connect-timeout 60 --max-time 600 -k -d action=restore_status -d vps_id={$id} https://myvps.interserver.net/vps_queue.php"));
	}
}
