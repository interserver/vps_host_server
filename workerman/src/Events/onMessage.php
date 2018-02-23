<?php

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;

return function($stdObject, $conn, $data) {
	$this->conn = $conn;
	echo $data.PHP_EOL;
	global $global;
	$conn->lastMessageTime = time();
	$data = json_decode($data, true);
	switch ($data['type']) {
		case 'timers':
			break;
		case 'self-update':
			exec('exec svn update --non-interactive /root/cpaneldirect');
			break;
		case 'ping':
			$conn->send('{"type":"pong"}');
			break;
		case 'run':
			$run_id = $data['id'];
			$this->running[$data['id']] = array(
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
			$this->running[$data['id']]['process'] = new React\ChildProcess\Process($data['command'], '/root/cpaneldirect', $env);
			$this->running[$data['id']]['process']->start($loop);
			$this->running[$data['id']]['process']->on('exit', function($exitCode, $termSignal) use ($data, $conn) {
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
				unset($this->running[$data['id']]);
			});
			$this->running[$data['id']]['process']->stdout->on('data', function($output) use ($data, $conn) {
				$json = array(
					'type' => 'running',
					'id' => $data['id'],
					'stdout' => $output
				);
				$conn->send(json_encode($json));
			});
			$this->running[$data['id']]['process']->stderr->on('data', function($output) {
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
					$this->running[$data['id']]['process']->stdin->write($data['stdin']);
			}
			break;
	}
};
