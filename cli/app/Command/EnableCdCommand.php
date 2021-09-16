<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class EnableCdCommand extends Command {
	public function brief() {
		return "EnableCds a Virtual Machine.";
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
	}

	public function execute($hostname) {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		if (!Vps::isVpsRunning($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to be powered on.");
			return 1;
		}
		$this->enableCdVps($hostname);
	}

/*
export PATH="$PATH:/usr/sbin:/sbin:/bin:/usr/bin:";
if [ "$(virsh dumpxml {$vps_vzid}|grep "disk.*cdrom")" != "" ]; then
    echo "Skipping Setup, CD-ROM Drive already exists in VPS configuration";
else
    if [ "{$url}" != "" ]; then
        virsh attach-disk {$vps_vzid} "{$url}" hda --targetbus ide --type cdrom --sourcetype file --config
    else
        virsh attach-disk {$vps_vzid} - hda --targetbus ide --type cdrom --sourcetype file --config
        virsh change-media {$vps_vzid} hda --eject --config
    fi;
    virsh shutdown {$vps_vzid};
    max=30
    echo "Waiting up to $max Seconds for graceful shutdown";
    start="$(date +%s)";
    while [ $(($(date +%s) - $start)) -le $max ] && [ "$(virsh list |grep {$vps_vzid})" != "" ]; do
        sleep 5s;
    done;
    virsh destroy {$vps_vzid};
    virsh start {$vps_vzid};
    bash /root/cpaneldirect/run_buildebtables.sh;
    /root/cpaneldirect/vps_refresh_vnc.sh {$vps_vzid};
fi;

*/

	public function enableCdVps($hostname) {
		$this->getLogger()->info('EnableCdping the VPS');
		$this->getLogger()->indent();
		$this->getLogger()->info('Sending Softwawre Power-Off');
		echo `/usr/bin/virsh shutdown {$hostname}`;
		$enableCdped = false;
		$waited = 0;
		$maxWait = 120;
		$sleepTime = 10;
		$continue = true;
		while ($waited <= $maxWait && $enableCdped == false) {
			if (Vps::isVpsRunning($hostname)) {
				$this->getLogger()->info('still running, waiting (waited '.$waited.'/'.$maxWait.' seconds)');
				sleep($sleepTime);
				$waited += $sleepTime;
			} else {
				$this->getLogger()->info('appears to have cleanly shutdown');
				$enableCdped = true;
			}
		}
		if ($enableCdped === false) {
			$this->getLogger()->info('Sending Hardware Power-Off');
			echo `/usr/bin/virsh destroy {$hostname};`;
		}
		$this->getLogger()->unIndent();
	}
}
