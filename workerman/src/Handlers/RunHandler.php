<?php

namespace MyAdmin\VpsHost\Handlers;

use MyAdmin\VpsHost\Agent;
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

/**
* 'run' - start a command as an async child process, streaming stdout/stderr back to
* the hub as {type:"running"} frames and the exit status as a {type:"ran"} frame.
*
* Verbatim port of the legacy onMessage.php 'run' switch-case: multi-line commands
* are written to a temp file and run via `bash -l <file>`; COLUMNS/LINES from
* cols/rows (defaults 80/24) merged over $_SERVER as the child env; every stdout/
* stderr chunk is sent as {type:"running", id, stdout|stderr} and completion as
* {type:"ran", id, code, term} (exactly one of code/term non-null, exit code
* propagated verbatim). After exit it honors update_after (vps_update_info +
* get_map_timer), removes the registry entry and unlinks the temp file.
*/
class RunHandler implements MessageHandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $data): void
	{
		$lines = explode("\n", trim($data['command']));
		$fileName = false;
		if (count($lines) > 1) {
			$fileName = tempnam('/tmp', 'command');
			file_put_contents($fileName, $data['command']);
			$data['command'] = 'bash -l '.$fileName;
		}
		$agent->running[$data['id']] = [
			'command' => $data['command'],
			'id' => $data['id'],
			'interact' => isset($data['interact']) ? $data['interact'] : false,
			'update_after' => isset($data['update_after']) ? $data['update_after'] : false,
			'for' => $data['for'],
			'process' => null,
			'pipes' => null,
			'process_stdin' => null,
			'process_stdout' => null,
			'process_stderr' => null,
		];
		// Workerman v5's Worker::getEventLoop() returns Workerman\Events\EventInterface,
		// which React\ChildProcess\Process::start() rejects (InvalidArgumentException).
		// The bridge exposes Workerman's live loop through React's LoopInterface.
		$loop = \MyAdmin\VpsHost\ReactLoopBridge::instance();
		$env = array_merge(['COLUMNS' => isset($data['cols']) ? $data['cols'] : 80, 'LINES' => isset($data['rows']) ? $data['rows'] : 24], $_SERVER);
		unset($env['argv']);
		$agent->running[$data['id']]['process'] = new \React\ChildProcess\Process($data['command'], __DIR__.'/../../../', $env);
		$agent->running[$data['id']]['process']->start($loop);
		$agent->running[$data['id']]['process']->on('exit', function ($exitCode, $termSignal) use ($data, $conn, $agent, $fileName) {
			if (is_null($termSignal)) {
				Worker::safeEcho("command '{$data['command']}' completed with exit code {$exitCode}\n");
			} else {
				Worker::safeEcho("command '{$data['command']}' terminated with signal {$termSignal}\n");
			}
			$this->sendExitFrame($conn, $data['id'], $exitCode, $termSignal);
			if ($data['update_after'] !== false) {
				$agent->vps_update_info();
				$agent->get_map_timer();
			}
			unset($agent->running[$data['id']]);
			if ($fileName !== false) {
				unlink($fileName);
			}
		});
		$agent->running[$data['id']]['process']->stdout->on('data', function ($output) use ($data, $conn) {
			$this->sendOutputFrame($conn, $data['id'], 'stdout', $output);
		});
		$agent->running[$data['id']]['process']->stderr->on('data', function ($output) use ($data, $conn) {
			$this->sendOutputFrame($conn, $data['id'], 'stderr', $output);
		});
	}

	/**
	* emit one stdout/stderr chunk to the hub. Default = the exact legacy
	* {type:"running", id, stdout|stderr} frame; Handlers\V1\CmdExecHandler
	* overrides this to emit the v1 cmd.output envelope instead, so the
	* process-spawn/stream core above stays single-sourced.
	*/
	protected function sendOutputFrame(AsyncTcpConnection $conn, $id, string $stream, $chunk): void
	{
		$json = [
			'type' => 'running',
			'id' => $id,
			$stream => $chunk
		];
		$conn->send(json_encode($json));
	}

	/**
	* emit the completion frame. Default = the exact legacy
	* {type:"ran", id, code, term} frame (exit code propagated VERBATIM - the
	* E1 exit-code invariant); Handlers\V1\CmdExecHandler overrides this to
	* emit the v1 cmd.exit envelope with the same untouched code/term pair.
	*/
	protected function sendExitFrame(AsyncTcpConnection $conn, $id, $exitCode, $termSignal): void
	{
		$json = [
			'type' => 'ran',
			'id' => $id,
			'code' => $exitCode,
			'term' => $termSignal,
		];
		$conn->send(json_encode($json));
	}
}
