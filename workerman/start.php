<?php
use Workerman\Worker;
use Workerman\WebServer;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;

$composer = include __DIR__.'/vendor/autoload.php';
global $settings;
$settings = include __DIR__.'/settings.php';

class Events {
	public $var;
	public $vps_list;
	public $bandwidth;
	public $timers = [];

	public function __construct() {

	}
}

function vps_update_info_timer() {
	global $global, $settings;
	$task_connection = new AsyncTcpConnection('Text://'.$settings['servers']['task']['ip'].':'.$settings['servers']['task']['port']);
	$task_connection->send(json_encode(['function' => 'async_hyperv_get_list', 'args' => []]));
	$task_connection->onMessage = function($task_connection, $task_result) use ($task_connection) {
		 //var_dump($task_result);
		 $task_connection->close();
	};
	$task_connection->connect();
}

function vps_queue($cmds) {
	foreach ($cmds as $cmd) {
		if (preg_match('/\.php$', $cmd) && file_exists(__DIR__.'/../'.$cmd))
			include __DIR__.'/../'.$cmd;
		elseif (preg_match('/(\/[^ ]+).*$/m', $cmd, $matches))
			echo `$cmd`;
		else {
			if (!isset($react_client)) {
				$loop = Worker::getEventLoop();
				$react_factory = new React\Dns\Resolver\Factory();
				$react_dns = $react_factory->createCached('8.8.8.8', $loop);
				$react_factory = new React\HttpClient\Factory();
				$react_client = $react_factory->create($loop, $react_dns);
			}
			$request = $client->request('GET', 'https://myvps2.interserver.net/vps_queue.php?action='.$cmd);
			$request->on('error', function(Exception $e) use ($cmd) {
				echo "CMD {$cmd} Exception Error {$e->getMessage()}\n";
			});
			$request->on('response', function ($response) {
				$response->on('data', function ($data, $response) {
					echo `$data`;
				});
			});
			$request->end();
		}
	}
}

function vps_queue_timer() {
	global $global, $settings;
	$task_connection = new AsyncTcpConnection('Text://'.$settings['servers']['task']['ip'].':'.$settings['servers']['task']['port']); // Asynchronous link with the remote task service
	$task_connection->send(json_encode(['function' => 'vps_queue', 'args' => $global->settings['vps_queue']['cmds']])); // send data
	$task_connection->onMessage = function($task_connection, $task_result) use ($task_connection) {	// get the result asynchronously
		 //var_dump($task_result);
		 $task_connection->close(); // remember to turn off the asynchronous link after getting the result
	};
	$task_connection->connect(); // execute async link
}

if (ini_get('date.timezone') == '')
	ini_set('date.timezone', 'America/New_York');
if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1)
	ini_set('default_socket_timeout', 1200);

$globaldata_server = new \GlobalData\Server($settings['servers']['globaldata']['ip'], $settings['servers']['globaldata']['port']);

$task_worker = new Worker('Text://'.$settings['servers']['task']['ip'].':'.$settings['servers']['task']['port']); // task worker, using the Text protocol
$task_worker->count = $settings['servers']['task']['count']; // number of task processes can be opened more than needed
$task_worker->name = 'TaskWorker';
$task_worker->onWorkerStart = function($worker) {
	global $global, $settings;
	$global = new \GlobalData\Client($settings['servers']['globaldata']['ip'].':'.$settings['servers']['globaldata']['port']); // initialize the GlobalData client
};
$task_worker->onMessage = function($connection, $task_data) {
	$task_data = json_decode($task_data, true);			// Suppose you send json data
	//echo "Starting Task {$task_data['function']}\n";
	if (isset($task_data['function'])) {				// According to task_data to deal with the corresponding task logic
		if (in_array($task_data['function'], ['vps_queue'])) {
			//require_once __DIR__.'/../'.$task_data['function'].'.php';
			$return = isset($task_data['args']) ? call_user_func($task_data['function'], $task_data['args']) : call_user_func($task_data['function']);
		}
	}
	//echo "Ending Task {$task_data['function']}\n";
	$connection->send(json_encode($return));			// send the result
};

//$worker = new Worker('Websocket://'.$settings['servers']['ws']['ip'].':'.$settings['servers']['ws']['port']);
$worker = new Worker();
$worker->name = 'VpsServer';
//$worker->name = 'WebsocketServer';
// start the process, open a vmstat process, and broadcast vmstat process output to all browser clients
$worker->onWorkerStart = function($worker) {
	global $global, $settings, $events;
	$events = new Events();
	$global = new \GlobalData\Client($settings['servers']['globaldata']['ip'].':'.$settings['servers']['globaldata']['port']);	 // initialize the GlobalData client
	if (!isset($global->settings))
		$global->settings = $settings;
	if($worker->id === 0) { // The timer is set only on the process whose id number is 0, and the processes of other 1, 2, and 3 processes do not set the timer
		$events->timers['vps_update_info_timer'] = Timer::add($global->settings['timers']['vps_update_info'], 'vps_update_info_timer');
		$events->timers['vps_queue_timer'] = Timer::add($global->settings['timers']['vps_queue'], 'vps_queue_timer');
	}
	if ($global->settings['vmstat']['enable'] === TRUE) {
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
	}
	$context = [																						// Certificate is best to apply for a certificate
		'ssl' => [																						// use the absolute/full paths
			'local_cert' => '/home/my/files/apache_setup/interserver.net.crt',							// can also be a crt file
			'local_pk' => '/home/my/files/apache_setup/interserver.net.key',
			'cafile' => '/home/my/files/apache_setup/AlphaSSL.root.crt',
			'verify_peer' => false,
			'verify_peer_name' => false,
		]
	];
	$ws_connection= new AsyncTcpConnection('ws://my3.interserver.net:7272', $context);
	$ws_connection->transport = 'ssl';

	$ws_connection->onConnect = function($conn) {
		$conn->send('{"type":"login","client_name":"server","room_id":"1"}');
	};
	$ws_connection->onMessage = function($conn, $data) {
		echo $data.PHP_EOL;
	};

	$ws_connection->onError = function($connection, $code, $msg){
		echo "error: {$msg}\n";
	};
	$ws_connection->onClose = function($conn) use (&$worker) {
		echo 'Connection Closed, Shutting Down'.PHP_EOL;
		//$conn->close();
		Worker::stopAll();
	};
	$ws_connection->connect();
};

$worker->onConnect = function($connection) {
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
		/*$descriptorspec = [
			0 => ['pty'],
			1 => ['pty'],
			2 => ['pty']
		];*/
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
		$connection->process_stdout = new TcpConnection($pipes[1]);
		$connection->process_stdout->onMessage = function($process_connection, $data) use ($connection) {
			$connection->send('phptty:'.$data);
		};
		$connection->process_stdout->onClose = function($process_connection) use ($connection) {
			$connection->close(); // Close WebSocket connection on process exit.
		};
		$connection->process_stdin = new TcpConnection($pipes[2]);
		$connection->process_stdin->onMessage = function($process_connection, $data) use ($connection) {
			$connection->send('phptty:'.$data);
		};
	}
};

$worker->onMessage = function($connection, $data) {
	global $global;
	$connection->lastMessageTime = time();
	$data = json_decode($data, true);
	switch ($data['type']) {
		case 'login':
			// delete timer if successfull
			Timer::del($connection->auth_timer_id);
			break;
		case 'phptty':
			if ($global->settings['phptty']['client_input'] === TRUE)
				fwrite($connection->pipes[0], $data);
			break;
	}
};

$worker->onClose = function($connection) {
	global $global;
	if ($global->settings['phptty']['enable'] === TRUE) {
		$connection->process_stdin->close();
		$connection->process_stdout->close();
		fclose($connection->pipes[0]);
		$connection->pipes = null;
		proc_terminate($connection->process);
		proc_close($connection->process);
		$connection->process = null;
	}
};

$worker->onWorkerStop = function($worker) {
	global $global, $settings;
	if ($settings['phptty']['enable'] === TRUE) {
		foreach($worker->connections as $connection)
			$connection->close();
	}
	if ($settings['vmstat']['enable'] === TRUE) {
		@shell_exec('killall vmstat');
		@pclose($worker->process_handle);
	}
};

// If not in the root directory, run runAll method
if(!defined('GLOBAL_START'))
	Worker::runAll();
