<?php
namespace App\Os;

use App\Vps;
use App\Vps\Kvm;
use App\Vps\OpenVz;
use App\Vps\Virtuozzo;
use App\Os\Os;
use App\Os\Xinetd;
use App\Os\Dhcpd;
/**
* Xinetd Service Management Class
*/
class Xinetd
{

    /**
    * create the xinetd lock file
    */
	public static function lock() {
		touch('/tmp/_securexinetd');
	}

	/**
	* remove the xinetd lock file
	*/
	public static function unlock() {
		unlink('/tmp/_securexinetd');
	}

	/**
	* remove an xinetd.d entry
	* @param string $vzid vzid to remove
	*/
	public static function remove($vzid) {
		if (file_exists('/etc/xinetd.d/'.$vzid))
			unlink('/etc/xinetd.d/'.$vzid);
		if (file_exists('/etc/xinetd.d/'.$vzid.'-spice'))
			unlink('/etc/xinetd.d/'.$vzid.'-spice');
	}

	/**
	* restart xinetd services
	*/
	public static function restart() {
		echo Vps::runCommand("service xinetd restart 2>/dev/null || /etc/init.d/xinetd restart 2>/dev/null");
	}

    /**
    * is the service running
    * @return bool
    */
	public static function isRunning() {
		echo Vps::runCommand('pidof xinetd >/dev/null', $return);
		return $return == 0;
	}

	/**
	* parses all the files in /etc/xinetd.d loading all service defintions into a nicely structured array
	*
	* "vps450552": {
	* 	"filename": "/etc/xinetd.d/vps450552",
	* 	"type": "UNLISTED",
	* 	"disable": "no",
	* 	"socket_type": "stream",
	* 	"wait": "no",
	* 	"user": "nobody",
	* 	"redirect": "127.0.0.1 5934",
	* 	"bind": "69.10.36.142",
	* 	"only_from": "70.10.23.33 66.45.240.196 192.64.80.216\/29",
	* 	"port": "5934",
	* 	"nice": "10"
	* },
	*
	* @return array the parsed array of xinetd entreis
	*/
	public static function parseEntries() {
		$services = [];
		foreach (glob('/etc/xinetd.d/*') as $fileName) {
			$file = file_get_contents($fileName);
			if (preg_match_all('/^\s*service (\S+)\s*\n\s*{\s*\n(.*)^\s*}\s*$/msUu', $file, $matches)) {
				foreach ($matches[0] as $idx => $wholeSection) {
					$serviceName = $matches[1][$idx];
					$serviceSettings = $matches[2][$idx];
					$services[$serviceName] = [];
					$services[$serviceName]['filename'] = $fileName;
					if (preg_match_all('/^\s*(\w+)\s+(=|\+=|\-=)\s+(\S.*\S)\s*$/muU', $serviceSettings, $attribMatches)) {
						foreach ($attribMatches[1] as $attribIdx => $attribute) {
							$assignment = $attribMatches[2][$attribIdx];
							$value = $attribMatches[3][$attribIdx];
							$services[$serviceName][$attribute] = array_key_exists($attribute, $services[$serviceName]) ? $services[$serviceName][$attribute].' '.$value : $value;
						}
					}
				}
			}
		}
		return $services;
	}

	/**
	* cleans up and recreates all the xinetd vps entries
	*
	* @param bool $useAll true for QS hosts, default false
	* @param bool $dryRun true to disable actual removing or writing of files, default false to actually perform the rebuild
	* @param bool $force true to force rebuilding all entries, default false to reuse unchanged entries
	*/
	public static function rebuild($useAll = false, $dryRun = false, $force = false) {
		echo 'Geting Host Info...';
    	$host = Vps::getHostInfo($useAll);
    	echo 'done'.PHP_EOL;
    	$usedVzids = [];
		foreach ($host['vps'] as $vps) {
			if (isset($vps['vnc']) && trim($vps['vnc']) != '') {
				$usedVzids[$vps['id']] = $vps['vnc'];
				$usedVzids[$vps['hostname']] = $vps['vnc'];
				$usedVzids[$vps['vzid']] = $vps['vnc'];
			}
		}
        if (Vps::getVirtType() == 'virtuozzo') {
        	echo 'Getting Virtuozzo List...';
			$allVpsData = Virtuozzo::getList();
			echo 'done'.PHP_EOL;
	        $map = [
        		'name' => ['uuid' => [], 'veid' => []],
        		'uuid' => ['name' => [], 'veid' => []],
        		'veid' => ['name' => [], 'uuid' => []]
	        ];
			foreach ($allVpsData as $idx => $vps) {
				if (isset($usedVzids[$vps['Name']]))
					$usedVzids[$vps['EnvID']] = $usedVzids[$vps['Name']];
				if (isset($usedVzids[$vps['ID']]))
					$usedVzids[$vps['EnvID']] = $usedVzids[$vps['ID']];
				$map['uuid']['name'][$vps['ID']] = $vps['Name'];
				$map['uuid']['veid'][$vps['ID']] = $vps['EnvID'];
				$map['name']['uuid'][$vps['Name']] = $vps['ID'];
				$map['name']['veid'][$vps['Name']] = $vps['EnvID'];
				$map['veid']['name'][$vps['EnvID']] = $vps['Name'];
				$map['veid']['uuid'][$vps['EnvID']] = $vps['ID'];
			}
        }
        echo 'Getting All VMs...';
		$allVms = Vps::getAllVps();
		echo 'done'.PHP_EOL;
        // get a list of all vms  + vnc infos (virtuozzo) or get a list of all vms and iterate them getting vnc info on each
        echo 'Getting Running VMs...';
        $runningVps = Vps::getRunningVps();
        echo 'done'.PHP_EOL;
		$usedPorts = [];
        foreach ($runningVps as $vzid) {
			$remotes = Vps::getVpsRemotes($vzid);
			if (Vps::getVirtType() == 'virtuozzo' && array_key_exists($vzid, $map['name']['veid']))
				$vzid = $map['name']['veid'][$vzid];
			foreach ($remotes as $type => $port)
				$usedPorts[$port] = ['type' => $type, 'vzid' => $vzid];
        }
        // we should now have a list of in use ports mapped to vps names/vzids
        echo 'Parsing Services...';
		$services = self::parseEntries();
		echo 'done'.PHP_EOL;
		$configuredPorts = [];
		foreach ($services as $serviceName => $serviceData) {
			if ($force === false
				&& isset($serviceData['port'])
				&& array_key_exists($serviceData['port'], $usedPorts)
				&& $usedPorts[$serviceData['port']]['vzid'] == str_replace('-'.$usedPorts[$serviceData['port']]['type'], '', $serviceName)
				&& trim($serviceData['only_from']) == (array_key_exists(str_replace('-spice', '', $serviceName), $usedVzids) ? $usedVzids[str_replace('-spice', '', $serviceName)].' ' : '').'66.45.240.196 192.64.80.216/29'
			) {
				echo "keeping {$serviceData['filename']} its port and ip info still match\n";
				$configuredPorts[] = $serviceData['port'];
			} else {
				// look for things using ports 5900-6500 and look for things using vps names/vzids
				if ((isset($serviceData['port']) && intval($serviceData['port']) >= 5900 && intval($serviceData['port']) <= 6500)
					|| preg_match('/^vps(\d+|\d+-\w+)$/', $serviceName) || in_array(str_replace('-spice', '', $serviceName), $allVms)) {
					echo "removing {$serviceData['filename']}\n";
					if ($dryRun === false)
						unlink($serviceData['filename']);
				} else {
					echo "skipping service {$serviceName} not using port in vncable range and not usinb a vzid name\n";
				}
			}
		}
		$hostIp = Os::getIp();
		foreach ($usedPorts as $port => $portData) {
			if (in_array($port, $configuredPorts))
				continue;
			$type = $portData['type'];
			$vzid = $portData['vzid'];
			echo "setting up {$type} on {$vzid} port {$port}".(isset($usedVzids[$vzid]) ? " ip {$usedVzids[$vzid]}" : "")."\n";
			if ($dryRun === false)
				self::setup($type == 'vnc' ? $vzid : $vzid.'-'.$type, $port, isset($usedVzids[$vzid]) ? $usedVzids[$vzid] : false, $hostIp);
		}
	}

    /**
    * creates a xinetd.d entry for a given vzid
    * @param string $vzid service name
    * @param int $port port number
    * @param string $ip ip address
    * @param string $hostIp host ip address
    */
	public static function setup($vzid, $port, $ip = false, $hostIp = false) {
		$template = 'service '.$vzid.'
{
	type        = UNLISTED
	disable     = no
	socket_type = stream
	wait        = no
	user        = nobody
	redirect    = 127.0.0.1 '.$port.'
	bind        = '.($hostIp === false ? Os::getIp() : $hostIp).'
	only_from   = '.($ip !== false ? $ip.' ' : '').'66.45.240.196 192.64.80.216/29
	port        = '.$port.'
	nice        = 10
}
';
		file_put_contents('/etc/xinetd.d/'.$vzid, $template);
	}

	/**
	* gets a list of assignment operators used in an xinetd service defintion
	* @return array the opreators
	*/
	public static function getAssignments() {
		$assignments = ['=', '+=', '-='];
		return $assignments;
	}

	/**
	* gets a list of attributes usable in an xinetd service definition
	* @return array the attributes
	*/
	public static function getAttributes() {
		$attributes = ['access_times', 'banner', 'banner_fail', 'banner_success', 'bind', 'cps', 'deny_time', 'disable', 'enabled', 'env', 'flags', 'group', 'groups', 'id', 'include', 'includedir', 'instances', 'interface', 'libwrap', 'log_on_failure', 'log_on_success', 'log_type', 'max_load', 'mdns', 'nice', 'no_access', 'only_from', 'passenv', 'per_source', 'port', 'protocol', 'redirect', 'rlimit_as', 'rlimit_cpu', 'rlimit_data', 'rlimit_files', 'rlimit_rss', 'rlimit_stack', 'rpc_number', 'rpc_version', 'server', 'server_args', 'socket_type', 'type', 'umask', 'user', 'wait'];
		return $attributes;
	}
}
