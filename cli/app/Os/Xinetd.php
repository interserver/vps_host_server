<?php
namespace App\Os;

use App\Vps;
use App\Vps\Kvm;
use App\Vps\OpenVz;
use App\Vps\Virtuozzo;
use App\Os\Xinetd;
use APp\Os\Dhcpd;
/**
* Xinetd Service Management Class
*/
class Xinetd
{
/* {
    "vps450552": {
        "type": "UNLISTED",
        "disable": "no",
        "socket_type": "stream",
        "wait": "no",
        "user": "nobody",
        "redirect": "127.0.0.1 5934",
        "bind": "69.10.36.142",
        "only_from": "66.45.240.196 192.64.80.216\/29",
        "port": "5934",
        "nice": "10"
    },
    "vps456305": {
        "type": "UNLISTED",
        "disable": "no",
        "socket_type": "stream",
        "wait": "no",
        "user": "nobody",
        "redirect": "127.0.0.1 5911",
        "bind": "69.10.36.142",
        "only_from": "66.45.228.251 66.45.240.196 192.64.80.216\/29",
        "port": "5911",
        "nice": "10"
    }
} */


/* xinetd template
service NAME
{
		type                    = UNLISTED
		disable                 = no
		socket_type             = stream
		wait                    = no
		user                    = nobody
		redirect                = 127.0.0.1 PORT
		bind                    = MYIP
		only_from               = IP 66.45.240.196 192.64.80.216/29
		port                    = PORT
		nice                    = 10

}
*/

	public static function lock() {
		touch('/tmp/_securexinetd');
	}

	public static function unlock() {
		echo Vps::runCommand("rm -f /tmp/_securexinetd;");
	}

	public static function remove($vzid) {
		$vzid = escapeshellarg($vzid);
		echo Vps::runCommand("rm -f /etc/xinetd.d/{$vzid} /etc/xinetd.d/{$vzid}-spice");
	}

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
					if (preg_match_all('/^\s*(\w+)\s+(=|\+=|\-=)\s+(\S.*\S)\s*$/muU', $serviceSettings, $attribMatches)) {
						foreach ($attribMatches[1] as $attribIdx => $attribute) {
							$assignment = [2][$attribIdx];
							$value = $attribMatches[3][$attribIdx];
							$services[$serviceName][$attribute] = array_key_exists($attribute, $services[$serviceName]) ? $services[$serviceName][$attribute].' '.$value : $value;
						}
					}
				}
			}
		}
		return $services;
	}

	public static function rebuild() {
        // get a list of all vms  + vnc infos (virtguozzo) or get a list of all vms and iterate them getting vnc info on each
        $runningVms = Vps::getRunningVps();
		$usedPorts = [];
        foreach ($runningVps as $vzid) {
			$remotes = Kvm::getVpsRemotes($vzid);
			foreach ($remotes as $type => $port)
				$usedPorts[] = $port;
        }
        // we should now have a list of in use ports mapped to vps names/vzids
		$services = self::parseEntries();
		foreach ($services as $$serviceName => $serviceData) {
			// look for things using ports 5900-6500
			if (isset($serviceData['port'])) {

			}
			// look for things using vps names/vzids

		}
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
