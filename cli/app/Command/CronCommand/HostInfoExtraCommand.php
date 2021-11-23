<?php
namespace App\Command\CronCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class HostInfoExtraCommand extends Command {
	public function brief() {
		return "lists the history entries";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
	}

	public function execute() {
		Vps::init($this->getOptions(), []);
		$servers = array();
		// ensure ethtool is installed
		`if ! which ethtool 2>/dev/null; then if [ -e /etc/redhat-release ]; then yum install -y ethtool; else apt-get install -y ethtool; fi; fi;`;
		if (in_array(trim(`hostname`), array("kvm1.trouble-free.net", "kvm2.interserver.net", "kvm50.interserver.net"))) {
			$eth = 'eth1';
		} elseif (file_exists('/etc/debian_version')) {
			if (file_exists('/sys/class/net/p2p1')) {
				$eth = 'p2p1';
			} elseif (file_exists('/sys/class/net/em1')) {
				$eth = 'em1';
			} else {
				$eth = trim(`ip link show |grep "^[0-9]"|grep -v -e "lo:" -e "br[0-9]*:"|cut -d: -f2|head -n 1|sed s#" "#""#g`);
			}
		} else {
			$eth = trim(`ip link show |grep "^[0-9]"|grep -v -e "lo:" -e "br[0-9]*:"|cut -d: -f2|head -n 1|sed s#" "#""#g`);
		}

		//$speed = trim(`ethtool $eth |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g`);
		$cmd = 'ethtool '.$eth.' |grep Speed: | sed -e s#"^.* \([0-9]*\).*$"#"\1"#g';
		$speed = trim(`{$cmd}`);
		$servers['speed'] = $speed;
		if (preg_match_all('/^flags\s*:\s*(.*)$/m', file_get_contents('/proc/cpuinfo'), $matches)) {
			$flags = explode(' ', trim($matches[1][0]));
			sort($flags);
			$flagsnew = implode(' ', $flags);
			$flags = $flagsnew;
			unset($flagsnew);
			$servers['cpu_flags'] = $flags;
		}
		$url = 'https://mynew.interserver.net/vps_queue.php';
		$cmd = 'curl --connect-timeout 60 --max-time 600 -k -d action=server_info_extra -d servers="'.urlencode(base64_encode(serialize($servers))).'" "'.$url.'" 2>/dev/null;';
		// echo "CMD: $cmd\n";
		echo trim(`$cmd`);
	}
}
