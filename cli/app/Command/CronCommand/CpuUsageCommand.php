<?php
/**
* CPU Usage Updater - by Joe Huss <detain@interserver.net>
* Updates the CPU Usage at periodic intervals (10).  It measures the time
* spent each time getting + updating the usage, and if it ran faster than
* the interval time, it sleeps for the difference.  It repeats this entire
* process until a the total time spent is equal or greater than maxtime (60).
*
* How to get CPU Usage:
* - read the first line of   /proc/stat
* - discard the first word of that first line   (it's always cpu)
* - sum all of the times found on that first line to get the total time
* - divide the fourth column ("idle") by the total time, to get the fraction of time spent being idle
* - subtract the previous fraction from 1.0 to get the time spent being   not   idle
* - multiple by   100   to get a percentage
*/
namespace App\Command\CronCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class CpuUsageCommand extends Command {
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
		$usageFile = $_SERVER['HOME'].'/.provirted/cpu_usage.json';
		$usage = ['total' => [], 'idle' => []];
		@mkdir($_SERVER['HOME'].'/.provirted', 0750, true);
		$lastUsage = file_exists($usageFile) ? json_decode(file_get_contents($usageFile), true) : $usage;
		$cpu = [];
		$files = [];
		if (file_exists('/proc/vz/fairsched/cpu.proc.stat')) {
			foreach (glob('/proc/vz/fairsched/*/cpu.proc.stat') as $file) {
				$vzid = intval(basename(dirname($file)));
				$files[$vzid] = $file;
			}
		}
		$files[0] = '/proc/stat';
		foreach ($files as $vzid => $file) {
			$text = file_get_contents($file);
			if (preg_match_all('/^(?P<cpu>cpu[0-9]*)\s+(?P<user>\d+)\s+(?P<nice>\d+)\s+(?P<system>\d+)\s+(?P<idle>\d+)\s+(?<iowait>\d+)\s+(?P<irq>\d+)\s+(?P<softirq>\d+)\s*(?P<steal>\d*)\s*(?P<guest>\d*)/m', $text, $matches)) {
				$usage['total'][$vzid] = [];
				$usage['idle'][$vzid] = [];
				$cpu[$vzid] = [];
				foreach ($matches[0] as $idx => $line) {
					$cpuName = $matches['cpu'][$idx];
					$lastIdle = array_key_exists($vzid, $lastUsage['total']) && array_key_exists($cpuName, $lastUsage['total'][$vzid]) ? $lastUsage['total'][$vzid][$cpuName] : 0;
					$lastTotal = array_key_exists($vzid, $lastUsage['idle']) && array_key_exists($cpuName, $lastUsage['idle'][$vzid]) ? $lastUsage['idle'][$vzid][$cpuName] : 0;
					$totalTime = intval($matches['user'][$idx]) + intval($matches['nice'][$idx]) + intval($matches['system'][$idx]) + intval($matches['idle'][$idx]) + intval($matches['iowait'][$idx]) + intval($matches['irq'][$idx]) + intval($matches['softirq'][$idx]) + intval($matches['steal'][$idx]) + intval($matches['guest'][$idx]);
					$idleTime = intval($matches['idle'][$idx]);
					$idleTimeFraction = ($idleTime - $lastIdle) / ($totalTime - $lastTotal);
					$usedTime = 1.0 - $idleTime;
					$usedPct = round(100 * $usedTime, 2);
					$cpu[$vzid][$cpuName] = $usedPct;
					$usage['total'][$vzid][$cpuName] = $totalTime;
					$usage['idle'][$vzid][$cpuName] = $idleTime;
				}
			}
		}
		file_put_contents($usageFile, json_encode($usage));
		$cpu_usage = json_encode($cpu);
		echo `curl --connect-timeout 60 --max-time 600 -k -F action=cpu_usage -F "cpu_usage={$cpu_usage}" "http://mynew.interserver.net:55151/queue.php" 2>/dev/null`;
	}
}
