<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\V1Envelope;
use MyAdmin\VpsHost\Handlers\StopRunHandler;
use Workerman\Connection\AsyncTcpConnection;

/**
* v1 'cmd.kill' (§2.2) - {run_id}: close pipes + terminate(SIGKILL), delegated
* to the UNCHANGED legacy StopRunHandler so the kill logic stays single-sourced.
*
* IMPORTANT: this op is ADDITIONAL to legacy stop_run, which remains registered
* in the legacy MessageDispatcher - the hub's onClose admin-disconnect sweep
* still sends legacy {type:"stop_run", id} even for v1-originated runs (Phase 2
* carried-forward requirement), and both address the same $agent->running key.
*/
class CmdKillHandler implements V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		$d = $envelope['data'];
		$runId = $d['run_id'] ?? null;
		if (!is_string($runId) || $runId === '') {
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'bad_request', 'cmd.kill requires run_id')));
			return;
		}
		if (!isset($agent->running[$runId]['process']) || $agent->running[$runId]['process'] === null) {
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'not_found', 'no such run_id '.$runId)));
			return;
		}
		(new StopRunHandler())->handle($agent, $conn, ['type' => 'stop_run', 'id' => $runId]);
	}
}
