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
		return "Load a CD image into an existing CD-ROM in a Virtual Machine.";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
		$args->add('vzid')->desc('VPS id/name to use')->isa('string');
		$args->add('url')->desc('CD image URL')->isa('string');
	}

	public function execute($vzid, $url) {
		Vps::init($this->getOptions(), ['vzid' => $vzid, 'url' => $url]);
		if (!Vps::isVirtualHost()) {
			$this->getLogger()->error("This machine does not appear to have any virtualization setup installed.");
			$this->getLogger()->error("Check the help to see how to prepare a virtualization environment.");
			return 1;
		}
		if (!Vps::vpsExists($vzid)) {
			$this->getLogger()->error("The VPS '{$vzid}' you specified does not appear to exist, check the name and try again.");
			return 1;
		}
		$parts = parse_url($url);
		if (!array_key_exists('port', $parts)) {
			$parts['port'] = trim(Vps::runCommand("grep \"^{$parts['scheme']}\\s\" /etc/services |grep \"/tcp\\s\"|cut -d/ -f1|awk \"{ print \\$2 }\""));
		}
		$str =
"<disk type='network' device='cdrom'>
  <driver name='qemu' type='raw'/>
  <target dev='hda' bus='ide'/>
  <readonly/>
  <source protocol='{$parts['scheme']}' name='{$parts['path']}'>
    <host name='{$parts['host']}' port='{$parts['port']}'/>
  </source>
</disk>";
		file_put_contents('/root/disk.xml', $str);
		echo Vps::runCommand("virsh update-device {$vzid} /root/disk.xml --live");
		echo Vps::runCommand("virsh update-device {$vzid} /root/disk.xml --config");
		echo Vps::runCommand("rm -f /root/disk.xml");
		echo Vps::runCommand("virsh reboot {$vzid}");
	}

}
