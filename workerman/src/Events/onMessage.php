<?php

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;

return function($stdObject, $conn, $data) {
	$stdObject->conn = $conn;
	echo $data.PHP_EOL;
	global $global;
	$conn->lastMessageTime = time();
	$data = json_decode($data, true);
	switch ($data['type']) {
		case 'timers':
			break;
		case 'self-update':
			sleep(rand(0, 60));
			echo exec('exec svn update --accept theirs-full --username vpsclient --password interserver123 --trust-server-cert --non-interactive /root/cpaneldirect').PHP_EOL;
			echo exec('php '.__DIR__.'/../../start.php reload').PHP_EOL;
			break;
		case 'ping':
			$conn->send('{"type":"pong"}');
			break;
		case 'run':
			$run_id = $data['id'];
			$stdObject->running[$data['id']] = array(
				'command' => $data['command'],
				'id' => $data['id'],
				'interact' => $data['interact'],
				'for' => $data['for'],
				'process' => null,
				'pipes' => null,
				'process_stdin' => null,
				'process_stdout' => null,
				'process_stderr' => null,
			);
			$loop = Worker::getEventLoop();
			$env = array_merge(array('COLUMNS' => 80, 'LINES' => 24), $_SERVER);
			unset($env['argv']);
			$stdObject->running[$data['id']]['process'] = new React\ChildProcess\Process($data['command'], '/root/cpaneldirect', $env);
			$stdObject->running[$data['id']]['process']->start($loop);
			$stdObject->running[$data['id']]['process']->on('exit', function($exitCode, $termSignal) use ($data, $conn, &$stdObject) {
				if (is_null($termSignal))
					echo "command '{$data['command']}' completed with exit code {$exitCode}\n";
				else
					echo "command '{$data['command']}' terminated with signal {$termSignal}\n";
				$json = array(
					'type' => 'ran',
					'id' => $data['id'],
					'code' => $exitCode,
					'term' => $termSignal,
				);
				$conn->send(json_encode($json));
				unset($stdObject->running[$data['id']]);
			});
			$stdObject->running[$data['id']]['process']->stdout->on('data', function($output) use ($data, $conn) {
				$json = array(
					'type' => 'running',
					'id' => $data['id'],
					'stdout' => $output
				);
				$conn->send(json_encode($json));
			});
			$stdObject->running[$data['id']]['process']->stderr->on('data', function($output) use ($data, $conn) {
				$json = array(
					'type' => 'running',
					'id' => $data['id'],
					'stderr' => $output
				);
				$conn->send(json_encode($json));
			});
			break;
		case 'running':
			if (isset($data['id'])) {
					$stdObject->running[$data['id']]['process']->stdin->write($data['stdin']);
			}
			break;
	}
};
