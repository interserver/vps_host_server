<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\V1Envelope;
use MyAdmin\VpsHost\Handlers\RunHandler;
use Workerman\Connection\AsyncTcpConnection;

/**
* v1 'cmd.exec' (§2.2) - spawn a streamed command. Extends the legacy
* RunHandler so the entire process-spawn/stream core (temp-file for multi-line
* commands, COLUMNS/LINES env, React\ChildProcess via ReactLoopBridge::instance()
* - NEVER Worker::getEventLoop(), see BASELINE §10 - registry bookkeeping,
* update_after chaining, temp-file cleanup) stays single-sourced; only the two
* frame builders are overridden to emit v1 envelopes:
*
*   cmd.output {run_id, stream:"stdout"|"stderr", data}   (A->H, no reply)
*   cmd.exit   {run_id, code, term}                       (A->H, no reply)
*
* Exit-code invariant E1: code/term are handed to sendExitFrame exactly as
* React reports them and placed in the envelope unmodified.
*
* The run is registered in $agent->running under its run_id, so the legacy
* stop_run / running / run_list handlers address v1-originated runs too -
* required because the hub's onClose admin-disconnect sweep still sends
* legacy {type:"stop_run", id} even for v1 runs.
*/
class CmdExecHandler extends RunHandler implements V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		$d = $envelope['data'];
		if (!isset($d['run_id']) || !is_string($d['run_id']) || $d['run_id'] === ''
			|| !isset($d['command']) || !is_string($d['command']) || trim($d['command']) === '') {
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'bad_request', 'cmd.exec requires run_id and command')));
			return;
		}
		if (isset($agent->running[$d['run_id']])) {
			// v1 requires unique run_ids (no md5($cmd) collisions); reject reuse
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'bad_request', 'run_id already in flight')));
			return;
		}
		// map the frozen §2.2 fields (with the CORRECTED defaults: rows=24
		// height->LINES, cols=80 width->COLUMNS) onto the legacy-internal shape
		// RunHandler::handle() consumes; 'id' = run_id keys $agent->running
		parent::handle($agent, $conn, [
			'type' => 'run',
			'id' => $d['run_id'],
			'command' => $d['command'],
			'interact' => (bool)($d['interact'] ?? false),
			'rows' => (int)($d['rows'] ?? 24),
			'cols' => (int)($d['cols'] ?? 80),
			'update_after' => (bool)($d['update_after'] ?? false),
			'for' => $d['for'] ?? null,
		]);
	}

	/**
	* v1 wire shape for stdout/stderr chunks (replaces the legacy
	* {type:"running", id, stdout|stderr} frame on this path only).
	*/
	protected function sendOutputFrame(AsyncTcpConnection $conn, $id, string $stream, $chunk): void
	{
		$conn->send(json_encode(V1Envelope::request('cmd.output', [
			'run_id' => $id,
			'stream' => $stream,
			'data' => $chunk
		])));
	}

	/**
	* v1 wire shape for completion; code/term propagated VERBATIM (E1).
	*/
	protected function sendExitFrame(AsyncTcpConnection $conn, $id, $exitCode, $termSignal): void
	{
		$conn->send(json_encode(V1Envelope::request('cmd.exit', [
			'run_id' => $id,
			'code' => $exitCode,
			'term' => $termSignal
		])));
	}
}
