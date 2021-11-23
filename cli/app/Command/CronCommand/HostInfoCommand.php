<?php
namespace App\Command\CronCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class HostInfoCommand extends Command {
	public function brief() {
		return "lists the history entries";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
		$opts->add('a|all', 'Use All Available HD, CPU Cores, and 70% RAM');
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
	}

	public function execute() {
		Vps::init($this->getOptions(), []);
		/** @var {\GetOptionKit\OptionResult|GetOptionKit\OptionCollection} */
		$opts = $this->getOptions();
        $useAll = array_key_exists('all', $opts->keys) && $opts->keys['all']->value == 1;
		$module = $useAll === true ? 'quickservers' : 'vps';
		$dir = Vps::$base;
		$root_used = trim(`df -P /| awk '{ print $5 }' |grep % | sed s#"%"#""#g`);
		//if ($root_used > 90) {
		//$hostname = trim(`hostname;`);
		//mail('hardware@interserver.net', $root_used.'% Disk Usage on '.$hostname, $root_used.'% Disk Usage on '.$hostname);
		//}
		//$url = 'https://mynew.interserver.net/vps_queue.php';
		$url = 'http://mynew.interserver.net:55151/queue.php';
		$server = array();
		$uname = posix_uname();
		$server['bits'] = $uname['machine'] == 'x86_64' ? 64 : 32;
		$server['kernel'] = $uname['release'];
		$server['raid_building'] = false;
		foreach (glob('/sys/block/md*/md/sync_action') as $file) {
			if (trim(file_get_contents($file)) != 'idle') {
				$server['raid_building'] = true;
			}
		}
		$file = explode(' ', trim(file_get_contents('/proc/loadavg')));
		$server['load'] = (float)$file[0];
		$file = explode("\n\n", trim(file_get_contents('/proc/cpuinfo')));
		$server['cores'] = count($file);
		preg_match('/^cpu MHz.*: (.*)$/m', $file[0], $matches);
		$server['cpu_mhz'] = (float)$matches[1];
		preg_match('/^model name.*: (.*)$/m', $file[0], $matches);
		$server['cpu_model'] = $matches[1];
		preg_match('/MemTotal\s*\:\s*(\d+)/i', file_get_contents('/proc/meminfo'), $matches);
		$server['ram'] = (int)$matches[1];
		$badDir = '/vz/root/';
		$badDevs = array('proc', 'sysfs', 'devtmpfs', 'devpts', 'tmpfs', 'beancounter', 'fairsched', 'mqueue', 'cgroup', 'none');
		$badLen = strlen($badDir);
		preg_match_all('/^(?P<dev>\S+)\s+(?P<dir>\S+)\s+(?P<fs>\S+)\s+.*$/m', file_get_contents('/proc/mounts'), $matches);
		foreach ($matches[0] as $idx => $line) {
			$dev = $matches['dev'][$idx];
			$mountPoint = $matches['dir'][$idx];
			$fs = $matches['fs'][$idx];
			$matchDir = $matches[2][$idx];
			if (!in_array($dev, $badDevs) && substr($mountPoint, 0, $badLen) != $badDir && file_exists($matchDir)) {
				$total = floor(disk_total_space($matchDir) / 1073741824);
				$free = floor(disk_free_space($matchDir) / 1073741824);
				$used = $total - $free;
				$mounts[] = $dev.':'.$total.':'.$used.':'.$free.':'.$matchDir;
			}
		}
		preg_match_all('/^\s*([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):([^:]*):(.*)$/m', trim(`pvdisplay -c|grep :vz:`), $matches);
		foreach ($matches[1] as $idx => $value) {
			$dev = $value;
			$matchDir = $matches[2][$idx];
			$total = floor($matches[9][$idx] * $matches[8][$idx] / 1048576);
			$free = floor($matches[10][$idx] * $matches[8][$idx] / 1048576);
			$used = floor($matches[11][$idx] * $matches[8][$idx] / 1048576);
			$mounts[] = $dev.':'.$total.':'.$used.':'.$free.':'.$matchDir;
		}
		$server['mounts'] = implode(',', $mounts);
		if (trim(`which smartctl`) != '') {
			$server['drive_type'] = trim(`if [ "$(smartctl -i /dev/sda |grep "SSD")" != "" ]; then echo SSD; else echo SATA; fi`);
		}
		$cmd = '"${PERL:-perl}" -I"'.Vps::$base.'/nagios-plugin-check_raid/lib" "'.Vps::$base.'/nagios-plugin-check_raid/bin/check_raid.pl" "--check=WARNING" 2>/dev/null';
		$server['raid_status'] = trim(`{$cmd}`);
		if ($server['raid_status'] == 'check_raid UNKNOWN - No active plugins (No RAID found)') {
			$server['raid_status'] = 'OK: none:No Raid found';
		}
		if (file_exists('/sbin/zpool') || file_exists('/usr/sbin/zpool')) {
			preg_match('/^([^:]*): (.*)$/', $server['raid_status'], $matches);
			if (!isset($matches[2]) || trim($matches[2]) == '') {
				$parts = array();
			} else {
				$parts = explode('; ', $matches[2]);
			}
			$zfs_status = trim(file_exists('/sbin/zpool') ? `/sbin/zpool status -x` : `/usr/sbin/zpool status -x`);
			if (!isset($matches[1]) || $matches[1] == 'OK') {
				if ($zfs_status != 'all pools are healthy') {
					$matches[1] = 'WARNING';
				} else {
					$matches[1] = 'OK';
				}
			}
			$parts[] = 'zfs:'.$zfs_status;
			$server['raid_status'] = $matches[1].': '.implode('; ', $parts);
		}
		if (file_exists('/usr/bin/iostat')) {
			$server['iowait'] = trim(`iostat -c  |grep -v "^$" | tail -n 1 | awk '{ print $4 }';`);
		}
		$cmd = 'if [ "$(which vzctl 2>/dev/null)" = "" ]; then
		iodev="/$(pvdisplay -c |grep :vz:|cut -d/ -f2- |cut -d: -f1|head -n 1)";
		else
		iodev=/vz;
		fi;
		ioping -c 3 -s 100m -D -i 0 ${iodev} -B | cut -d" " -f2;';
		$server['ioping'] = trim(`$cmd`);
		if (file_exists('/sbin/zpool') || file_exists('/usr/sbin/zpool')) {
			$out = trim(`zpool list -Hp vz 2>/dev/null`);
			if ($out != '') {
				$parts = explode('	', $out);

				$totalb = $parts[1];
				$usedb = $parts[2];
				$freeb = $parts[3];
				$totalg = ceil($totalb / 1073741824);
				$freeg = ceil($freeb / 1073741824);
				$usedg = ceil($usedb / 1073741824);
				$out = $totalg.' '.$freeg;
			} else {
				unset($out);
			}
		}
		if (!isset($out) && file_exists('/usr/sbin/vzctl')) {
			$out = trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";df -B G /vz | grep -v ^Filesystem | awk '{ print \$2 " " \$4 }' |sed s#"G"#""#g;`);
		} elseif (!isset($out) && file_exists('/usr/bin/lxc')) {
			$parts = explode("\n", trim(`lxc storage info lxd --bytes|grep -e "space used:" -e "total space:"|cut -d'"' -f2`));
			$used = ceil($parts[0]/1073741824);
			$total = ceil($parts[1]/1073741824);
			$free = $total - $used;
			$out = $total.' '.$free;
		} elseif (!isset($out) && file_exists('/usr/bin/virsh')) {
			if (file_exists('/etc/redhat-release') && strpos(file_get_contents('/etc/redhat-release'), 'CentOS release 6') !== false) {
				$out = '';
			} else {
				$out = trim(`virsh pool-info vz --bytes|awk '{ print \$2 }'`);
			}
			if ($out != '') {
				$parts = explode("\n", $out);
				$totalb = $parts[5];
				$usedb = $parts[6];
				$freeb = $parts[7];
				$totalg = ceil($totalb / 1073741824);
				$freeg = ceil($freeb / 1073741824);
				$usedg = ceil($usedb / 1073741824);
				$out = $totalg.' '.$freeg;
			} elseif (trim(`lvdisplay  |grep 'Allocated pool';`) == '') {
				$parts = explode(':', trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/sbin:/usr/sbin"; pvdisplay -c|grep :vz:`));
				$pesize = $parts[7];
				$totalpe = $parts[8];
				$freepe = $parts[9];
				$totalg = ceil($pesize * $totalpe / 1048576);
				$freeg = ceil($pesize * $freepe / 1048576);
				$out = $totalg.' '.$freeg;
			} else {
				$TOTAL_GB = '$(lvdisplay --units g /dev/vz/thin |grep "LV Size" | sed s#"^.*LV Size"#""#g | sed s#"GiB"#""#g | sed s#" "#""#g | cut  -d\. -f1)';
				$USED_PCT = '$(lvdisplay --units g /dev/vz/thin |grep "Allocated .*data" | sed s#"Allocated.*data"#""#g |sort -nr| head -n1 |sed s#"%"#""#g)';
				$GB_PER_PCT = $TOTAL_GB / 100;
				$USED_GB = floor($USED_PCT * $GB_PER_PCT);
				$MAX_PCT =  60;
				$FREE_PCT = floor($MAX_PCT - $USED_PCT);
				$FREE_GB = floor($GB_PER_PCT * $FREE_PCT);
				$out = $TOTAL_GB.' '.$FREE_GB;
			}
		}
		if (isset($out)) {
			$parts = explode(' ', $out);
			if (sizeof($parts) == 2) {
				$server['hdsize'] = $parts[0];
				$server['hdfree'] = $parts[1];
			}
		}
		if (file_exists('/usr/sbin/vzctl')) {
			if (!file_exists('/proc/user_beancounters')) {
				$headers = "MIME-Version: 1.0\n";
				$headers .= "Content-type: text/html; charset=UTF-8\n";
				$headers .= "From: ".`hostname -s`." <hardware@interserver.net>\n";
				mail('hardware@interserver.net', 'OpenVZ server does not appear to be booted properly', 'This server does not have /proc/user_beancounters, was it booted into the wrong kernel?', $headers);
			}
		}
		$cmd = 'curl --connect-timeout 30 --max-time 60 -k -d module='.$module.' -d action=server_info -d servers="'.urlencode(base64_encode(json_encode($server))).'" "'.$url.'" 2>/dev/null;';
		// echo "CMD: $cmd\n";
		echo trim(`$cmd`);
	}
}
