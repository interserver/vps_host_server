<?php

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;

class Events {
	public $conn = null;
	public $hostname;
	public $var;
	public $vps_list = [];
	public $bandwidth = null;
	public $traffic_last = null;
	public $timers = [];
	public $ipmap = [];
	public $type;
	public $running = [];

	public function __construct() {
		$this->type = file_exists('/usr/sbin/vzctl') ? 'vzctl' : 'kvm';
		//Events::update_network_dev();
		$this->get_vps_ipmap();
		if (isset($_SERVER['HOSTNAME']))
			$this->hostname = $_SERVER['HOSTNAME'];
		else
			$this->hostname = trim(shell_exec('hostname -f 2>/dev/null||hostname'));
		if (!file_exists(__DIR__.'/myadmin.crt')) {
			echo "Generating new SSL Certificate for encrypted communications\n";
			echo shell_exec('echo -e "US\nNJ\nSecaucus\nInterServer\nAdministration\n'.$this->hostname.'"|/usr/bin/openssl req -utf8 -batch -newkey rsa:2048 -keyout '.__DIR__.'/myadmin.key -nodes -x509 -days 365 -out '.__DIR__.'/myadmin.crt -set_serial 0');
		}
	}

	public function onWorkerStart($worker) {
		global $global, $settings;
		$global = new \GlobalData\Client($settings['servers']['globaldata']['ip'].':'.$settings['servers']['globaldata']['port']);	 // initialize the GlobalData client
		if (!isset($global->settings))
			$global->settings = $settings;
		if($worker->id === 0) { // The timer is set only on the process whose id number is 0, and the processes of other 1, 2, and 3 processes do not set the timer
			//$events->timers['vps_update_info_timer'] = Timer::add($global->settings['timers']['vps_update_info'], 'vps_update_info_timer');
			//$events->timers['vps_queue_timer'] = Timer::add($global->settings['timers']['vps_queue'], 'vps_queue_timer');
		}
		$ws_connection= new AsyncTcpConnection('ws://my3.interserver.net:7272', getSslContext());
		$ws_connection->transport = 'ssl';
		$ws_connection->onConnect = [$this, 'onConnect'];
		$ws_connection->onMessage = [$this, 'onMessage'];
		$ws_connection->onError = [$this, 'onError'];
		$ws_connection->onClose = [$this, 'onClose'];
		$ws_connection->onWorkerStop = [$this, 'onWorkerStop'];
		$ws_connection->connect();
	}

	public function onConnect($conn) {
		$this->conn = $conn;
		$json = [
			'type' => 'login',
			'name' => $this->hostname,
			'module' => 'vps',
			'room_id' => 1,
			'ima' => 'host',
		];
		$conn->send(json_encode($json));
		$this->timers['vps_get_traffic'] = Timer::add(60, [$this, 'vps_get_traffic']);
		$this->vps_get_list();
		/*
		global $global;
		if ($global->settings['auth']['enable'] === TRUE) {
			$connection->auth_timer_id = Timer::add(30, function() use ($connection){
				$connection->close();
			}, null, false);
		}
		if ($global->settings['vmstat']['enable'] === TRUE) {
			$connection->send("vmstat:procs -----------memory---------- ---swap-- -----io---- -system-- ----cpu----\n");
			$connection->send("vmstat:r  b   swpd   free   buff  cache   si   so    bi    bo   in   cs us sy id wa\n");
		}
		if ($global->settings['phptty']['enable'] === TRUE) {
			//To do this, PHP_CAN_DO_PTS must be enabled. See ext/standard/proc_open.c in PHP directory.
			//$descriptorspec = [
			//	0 => ['pty'],
			//	1 => ['pty'],
			//	2 => ['pty']
			//];
			//Pipe can not do PTY. Thus, many features of PTY can not be used. e.g. sudo, w3m, luit, all C programs using termios.h, etc.
			$descriptorspec = [
				0 => ['pipe','r'],
				1 => ['pipe','w'],
				2 => ['pipe','w']
			];
			unset($_SERVER['argv']);
			$env = array_merge(['COLUMNS' => 130, 'LINES' => 50], $_SERVER);
			$connection->process = proc_open($global->settings['phptty']['cmd'], $descriptorspec, $pipes, null, $env);
			$connection->pipes = $pipes;
			stream_set_blocking($pipes[0], 0);
			$this->running[$data['id']]['process_stdout'] = new TcpConnection($pipes[1]);
			$this->running[$data['id']]['process_stdout']->onMessage = function($process_connection, $data) use ($connection) {
				$connection->send('phptty:'.$data);
			};
			$this->running[$data['id']]['process_stdout']->onClose = function($process_connection) use ($connection) {
				$connection->close(); // Close WebSocket connection on process exit.
			};
			$this->running[$data['id']]['process_stdin'] = new TcpConnection($pipes[2]);
			$this->running[$data['id']]['process_stdin']->onMessage = function($process_connection, $data) use ($connection) {
				$connection->send('phptty:'.$data);
			};
		}
		*/
	}

	public function onMessage($conn, $data) {
		$this->conn = $conn;
		echo $data.PHP_EOL;
		global $global;
		$conn->lastMessageTime = time();
		$data = json_decode($data, true);
		switch ($data['type']) {
			case 'ping':
				$conn->send('{"type":"pong"}');
				break;
			case 'run':
				/*	$data = [
						'type' => 'run',
						'command' => $cmd,
						'id' => $data['id'],
						'interact' => false,
						'host' => $uid,
						'for' => $for
					]; */
				// Save the process handle, close the handle when the process is closed
				$run_id = $data['id'];
				$this->running[$data['id']] = [
					'command' => $data['cmd'],
					'id' => $data['id'],
					'interact' => $data['interact'],
					'for' => $data['for'],
					'process' => null,
					'pipes' => null,
					'process_stdin' => null,
					'process_stdout' => null,
					'process_stderr' => null,

				];
				$loop = Worker::getEventLoop();
				$this->running[$data['id']]['process'] = new React\ChildProcess\Process($data['command']);
				$this->running[$data['id']]['process']->start($loop);
				$this->running[$data['id']]['process']->on('exit', function($exitCode, $termSignal) use ($this, $data, $conn) {
					if (is_null($termSignal))
						echo "command '{$data['command']}' terminated with signal {$termSignal}\n";
					else
						echo "command '{$data['command']}' completed with exit code {$exitCode}\n";
					$json = [
						'type' => 'ran',
						'id' => $data['id'],
						'code' => $exitCode,
						'term' => $termSignal,
					];
					$conn->send(json_encode($json));
					unset($this->running[$data['id']]);
				});
				$this->running[$data['id']]['process']->stdout->on('data', function($output) use ($data, $conn) {
					$json = [
						'type' => 'ran',
						'id' => $data['id'],
						'stdout' => $output
					];
					$conn->send(json_encode($json));
				});
				$this->running[$data['id']]['process']->stderr->on('data', function($output) {
					$json = [
						'type' => 'ran',
						'id' => $data['id'],
						'stderr' => $output
					];
					$conn->send(json_encode($json));
				});
				break;
			case 'running':
				if (isset($data['id'])) {
						$this->running[$data['id']]['process']->stdin->write($data['stdin']);
				}
				break;
			case 'vmstat_start':
				// Save the process handle, close the handle when the process is closed
				$worker->process_handle = popen('vmstat 1', 'r');
				if ($worker->process_handle) {
					$process_connection = new TcpConnection($worker->process_handle);
					$process_connection->onMessage = function($process_connection, $data) use ($worker) {
						foreach($worker->connections as $connection) {
							$connection->send('vmstat:'.$data);
						}
					};
				} else {
					echo "vmstat 1 fail\n";
				}

				break;
			case 'phptty':
				if ($global->settings['phptty']['client_input'] === TRUE)
					fwrite($conn->pipes[0], $data);
				break;
		}
	}

	public function onError($connection, $code, $msg){
		echo "error: {$msg}\n";
	}

	public function onClose($conn) {
		echo 'Connection Closed, Shutting Down'.PHP_EOL;
		//$conn->close();
		$conn->reConnect(5);
		//Worker::stopAll();
		/*
		global $global;
		if ($global->settings['phptty']['enable'] === TRUE) {
			$this->running[$data['id']]['process_stdin']->close();
			$this->running[$data['id']]['process_stdout']->close();
			fclose($connection->pipes[0]);
			$connection->pipes = null;
			proc_terminate($connection->process);
			proc_close($connection->process);
			$connection->process = null;
		}
		*/
	}

	public function onWorkerStop($worker) {
		/*
		global $global, $settings;
		if ($settings['phptty']['enable'] === TRUE) {
			foreach($worker->connections as $connection)
				$connection->close();
		}
		if ($settings['vmstat']['enable'] === TRUE) {
			@shell_exec('killall vmstat');
			@pclose($worker->process_handle);
		}
		*/
	}

	public function get_vps_ipmap() {
		if ($this->type == 'kvm')
			$output = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; if [ -e /etc/dhcp/dhcpd.vps ]; then DHCPVPS=/etc/dhcp/dhcpd.vps; else DHCPVPS=/etc/dhcpd.vps; fi;  grep "^host" \$DHCPVPS | tr \; " " | awk '{ print $2 " " $8 }'`);
		else
			$output = rtrim(`/usr/sbin/vzlist -H -o veid,ip 2>/dev/null`);
		$lines = explode("\n", $output);
		$ipmap = array();
		foreach ($lines as $line) {
			$parts = explode(' ', trim($line));
			if (sizeof($parts) > 1) {
				$id = $parts[0];
				$ip = $parts[1];
				if (validIp($ip, false) == true) {
					$extra = trim(`touch /root/cpaneldirect/vps.ipmap ; export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";grep "^$ip:" /root/cpaneldirect/vps.ipmap | cut -d: -f2`);
					if ($extra != '')
						$parts = array_merge($parts, explode("\n", $extra));
					for ($x = 1; $x < sizeof($parts); $x++)
						if ($parts[$x] != '-')
							$ipmap[$parts[$x]] = $id;
				}
			}
		}
		$this->ipmap = $ipmap;
		return $ipmap;
	}

	public function vps_iptables_traffic_rules() {
		$cmd = '';
		foreach ($this->ipmap as $ip => $id) {
			$cmd .= '/sbin/iptables -D FORWARD -d '.$ip.' 2>/dev/null;';
			$cmd .= '/sbin/iptables -D FORWARD -s '.$ip.' 2>/dev/null;';
			// run it twice to be safe
			$cmd .= '/sbin/iptables -D FORWARD -d '.$ip.' 2>/dev/null;';
			$cmd .= '/sbin/iptables -D FORWARD -s '.$ip.' 2>/dev/null;';
			$cmd .= '/sbin/iptables -A FORWARD -d '.$ip.';';
			$cmd .= '/sbin/iptables -A FORWARD -s '.$ip.';';
		}
		`$cmd`;
	}

	public function get_vps_iptables_traffic() {
		$totals = array();
		if ($this->type == 'kvm') {
			if (is_null($this->traffic_last) && file_exists('/root/.traffic.last'))
				$this->traffic_last = unserialize(file_get_contents('/root/.traffic.last'));
			$vnetcounters = trim(`grep vnet /proc/net/dev | tr : " " | awk '{ print $1 " " $2 " " $10 }'`);
			if ($vnetcounters != '') {
				$vnetcounters = explode("\n", $vnetcounters);
				$vnets = array();
				foreach ($vnetcounters as $line) {
					list($vnet, $out, $in) = explode(' ', $line);
					//echo "Got    VNet:$vnet   IN:$in    OUT:$out\n";
					$vnets[$vnet] = array('in' => $in, 'out' => $out);
				}
				$cmd = 'grep -H -i fe /sys/devices/virtual/net/vnet*/address 2>/dev/null| sed s#"/sys/devices/virtual/net/\([^/]*\)/address:fe:\(.*\)$"#"\1 52:\2"#g';
				$vnetmacs = trim(`$cmd`);
				if ($vnetmacs != '') {
					$vnetmacs = explode("\n", $vnetmacs);
					$macs = array();
					foreach ($vnetmacs as $line) {
						list($vnet, $mac) = explode(' ', $line);
						//echo "Got  VNet:$vnet   Mac:$mac\n";
						$vnets[$vnet]['mac'] = $mac;
						$macs[$mac] = $vnet;
					}
					$cmd = 'if [ -e /etc/dhcp/dhcpd.vps ]; then cat /etc/dhcp/dhcpd.vps; else cat /etc/dhcpd.vps; fi | grep ethernet | sed s#"^host \([a-z0-9\.]*\) { hardware ethernet \([^;]*\); fixed-address \([0-9\.]*\);}$"#"\2 \1 \3"#g';
					$macvps = explode("\n", trim(`$cmd`));
					$vpss = array();
					foreach ($macvps as $line) {
						list($mac, $vps, $ip) = explode(' ', $line);
						//echo "Got  Mac:$mac   VPS:$vps   IP:$ip\n";
						if (isset($macs[$mac]) && isset($vnets[$macs[$mac]])) {
							$vpss[$vps] = $vnets[$macs[$mac]];
							$vpss[$vps]['ip'] = $ip;
							if (isset($last) && isset($vpss[$vps])) {
								$in_new = bcsub($vpss[$vps]['in'], $last[$vps]['in'], 0);
								$out_new = bcsub($vpss[$vps]['out'], $last[$vps]['out'], 0);
							}
							elseif (isset($last))
							{
								$in_new = $last[$vps]['in'];
								$out_new = $last[$vps]['out'];
							} else {
								$in_new = $vpss[$vps]['in'];
								$out_new = $vpss[$vps]['out'];
							}
							if ($in_new > 0 || $out_new > 0)
								$totals[$ip] = array('in' => $in_new, 'out' => $out_new);
						}
					}
					if (sizeof($totals) > 0) {
						$this->traffic_last = $vpss;
						file_put_contents('/root/.traffic.last', serialize($vpss));
					}
				}
			}
		} else {
			foreach ($this->ipmap as $ip => $id) {
				if (validIp($ip, false) == true) {
					$lines = explode("\n", trim(`/sbin/iptables -nvx -L FORWARD 2>/dev/null | grep -v DROP  | awk '{ print " " $7 " " $8 " " $2 }' | grep -vi "[a-z]" | sort -n | grep " $ip " | awk '{ print $3 }'`));
					if (sizeof($lines) == 2) {
						list($in,$out) = $lines;
						$total = $in + $out;
						if ($total > 0)
							$totals[$ip] = array('in' => $in, 'out' => $out);
					}
				}
			}

			`PATH="\$PATH:/sbin:/usr/sbin"  iptables -Z`;
			$this->vps_iptables_traffic_rules();
		}
		$this->bandwidth = $totals;
		return $totals;
	}

	public function vps_get_traffic() {
		$totals = $this->get_vps_iptables_traffic();
		$this->conn->send(json_encode([
			'type' => 'bandwidth',
			'content' => $totals,
		]));
	}

	public function vps_get_list() {
		global $global, $settings;
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
		$task_connection->send(json_encode(['function' => 'vps_get_list', 'args' => ['type' => $this->type]]));
		$conn = $this->conn;
		$task_connection->onMessage = function($task_connection, $task_result) use ($conn) {
			//var_dump($task_result);
			$task_connection->close();
			$conn->send($task_result);
		};
		$task_connection->connect();
	}
}
