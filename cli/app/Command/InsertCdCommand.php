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
		return "Insert a CD-ROM into a Virtual Machine.";
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('hostname')->desc('Hostname to use')->isa('string');
		$args->add('url')->desc('CD image URL')->isa('string');
	}

	public function execute($hostname, $url) {
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($hostname)) {
			$this->getLogger()->error("The VPS '{$hostname}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		$parts = parse_url($url);
		if (!array_key_exists('port', $parts)) {
			$parts['port'] = trim(`grep "^{$proto}\\s" /etc/services |grep "/tcp\\s"|cut -d/ -f1|awk "{ print \\$2 }"`);
		}
		$str = "<disk type='network' device='cdrom'>
  <driver name='qemu' type='raw'/>
  <target dev='hda' bus='ide'/>
  <readonly/>
  <source protocol='{$parts['scheme']}' name='{$parts['path']}'>
    <host name='{$parts['host']}' port='{$parts['port']}'/>
  </source>
</disk>";
		file_put_contents('/root/disk.xml', $str);
		echo `virsh update-device {$hostname} /root/disk.xml --live`;
		echo `virsh update-device {$hostname} /root/disk.xml --config`;
		echo `rm -f /root/disk.xml`;
		echo `virsh reboot {$hostname}`;
	}

}
