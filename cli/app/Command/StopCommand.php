<?php
namespace App\Command;

use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class StopCommand extends Command {
    public $error = 0;

	public function brief() {
		return "Stops a Virtual Machine.";
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
	}

	public function execute($hostname) {
		if (!$this->isVirtualHost()) {
			$this->getLogger()->writeln("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->writeln("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		$this->stopVps($hostname);
	}

    public function getInstalledVirts() {
		$found = [];
		foreach ($this->virtBins as $virt => $virtBin) {
			if (file_exists($virtBin)) {
				$found[] = $virt;
			}
		}
		return $found;
    }

    public function isVirtualHost() {
		$virts = $this->getInstalledVirts();
		return count($virts) > 0;
    }

    public function getRunningVps() {
		return explode("\n", trim(`virsh list --name`));
    }

    public function isVpsRunning($hostname) {
		return in_array($hostname, $this->getRunningVps());
    }

	public function vpsExists($hostname) {
		passthru('/usr/bin/virsh dominfo '.$hostname.' >/dev/null 2>&1', $return);
		return $return == 0;
	}

	public function stopVps($hostname) {
		if ($this->error == 0) {
			$this->getLogger()->info2('Stopping the VPS');
			$this->getLogger()->indent();
			$this->getLogger()->info2('Sending Softwawre Power-Off');
			echo `/usr/bin/virsh stop {$hostname};`;
			$stopped = false;
			$waited = 0;
			$maxWait = 240;
			$sleepTime = 10;
			$continue = true;
			while ($waited <= $maxWait && $stopped == false) {
				if ($this->isVpsRunning($hostname)) {
					$this->getLogger()->info2('VPS is still running, waiting '.$sleepTime.' seconds (waited '.$waited.'/'.$maxWait.')');
					sleep($sleepTime);
					$waited += $sleepTime;
				} else {
					$stopped = true;
				}
			}
			if ($stopped === false) {
				$this->getLogger()->info2('Sending Hardware Power-Off');
				echo `/usr/bin/virsh destroy {$hostname};`;
			}
			$this->getLogger()->unIndent();
		}
	}
}
