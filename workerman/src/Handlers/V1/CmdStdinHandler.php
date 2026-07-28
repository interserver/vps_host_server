<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\V1Envelope;
use Workerman\Connection\AsyncTcpConnection;

/**
* v1 'cmd.stdin' (§2.2) - {run_id, data} raw stdin bytes for an interactive
* run. The v1 split of the legacy overloaded {type:"running", id, stdin}
* (RunningHandler) - same underlying process->stdin->write(). No reply on
* success; errors get an ok:false reply per §1.
*/
class CmdStdinHandler implements V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		$d = $envelope['data'];
		$runId = $d['run_id'] ?? null;
		$input = $d['data'] ?? null;
		if (!is_string($runId) || $runId === '' || !is_string($input)) {
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'bad_request', 'cmd.stdin requires run_id and data')));
			return;
		}
		if (!isset($agent->running[$runId]['process']) || $agent->running[$runId]['process'] === null) {
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'not_found', 'no such run_id '.$runId)));
			return;
		}
		$agent->running[$runId]['process']->stdin->write($input);
	}
}
