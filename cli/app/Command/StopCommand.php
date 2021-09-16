<?php
namespace App\Command;

use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;
use CLIFramework\Component\Progress\ProgressBar;

class StopCommand extends Command {
	public $base = '/root/cpaneldirect';
	public $hostname = '';
	public $device = '';
	public $pool = '';
	public $ip = '';
    public $error = 0;
    public $kpartxOpts = '';

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
		$this->hostname = $hostname;
		$this->stopVps();
	}

    public function initVariables($hostname, $ip, $template, $hd, $ram, $cpu, $password) {
        $this->url = $this->useAll == true ? 'https://myquickserver.interserver.net/qs_queue.php' : 'https://myvps.interserver.net/vps_queue.php';
        $this->kpartsOpts = preg_match('/sync/', `kpartx 2>&1`) ? '-s' : '';
		$this->pool = $this->getPoolType();
		$this->device = $this->pool == 'zfs' ? '/vz/'.$this->hostname.'/os.qcow2' : '/dev/vz/'.$this->hostname;
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

	public function vpsExists($hostname) {
		passthru('/usr/bin/virsh dominfo '.$hostname.' >/dev/null 2>&1', $return);
		return $return == 0;
	}

	public function getPoolType() {
		$pool = \xml2array(trim(`virsh pool-dumpxml vz 2>/dev/null`))['pool_attr']['type'];
		if ($pool == '') {
			echo `{$this->base}/create_libvirt_storage_pools.sh`;
			$pool = \xml2array(trim(`virsh pool-dumpxml vz 2>/dev/null`))['pool_attr']['type'];
		}
		if (preg_match('/vz/', `virsh pool-list --inactive`)) {
			echo `virsh pool-start vz;`;
		}
		return $pool;
	}

	public function stopVps() {
		if ($this->error == 0) {
			$this->getLogger()->info2('Stopping up the VPS');
			echo `/usr/bin/virsh stop {$this->hostname};`;
			echo `/usr/bin/virsh destroy {$this->hostname};`;
		}
	}
}
