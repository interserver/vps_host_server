<?php
namespace App\Command;

use App\Vps;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class InsertCdCommand extends Command {
	public function brief() {
		return "InsertCds a Virtual Machine.";
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
		$this->insertCdVps($hostname);
	}

/*
export PATH="$PATH:/usr/sbin:/sbin:/bin:/usr/bin:";
proto="$(echo "{$param}"|cut -d: -f1|tr "[A-Z]" "[a-z]")"
host="$(echo "{$param}"|cut -d/ -f3)"
if [ "$(echo "$host"|grep :)" = "" ]; then
    port="$(grep "^$proto\s" /etc/services |grep "/tcp\s"|cut -d/ -f1|awk "{ print \$2 }")"
else
    host="$(echo "$host"|cut -d: -f1)"
    port="$(echo "$host"|cut -d: -f2)"
fi
path="/$(echo "{$param}"|cut -d/ -f4-)"
echo "<disk type='network' device='cdrom'>
  <driver name='qemu' type='raw'/>
  <target dev='hda' bus='ide'/>
  <readonly/>
  <source protocol='$proto' name='$path'>
    <host name='$host' port='$port'/>
  </source>
</disk>" > /root/disk.xml;
virsh update-device {$hostname} /root/disk.xml --live
virsh update-device {$hostname} /root/disk.xml --config
rm -f /root/disk.xml;
virsh reboot {$hostname};

*/
}
