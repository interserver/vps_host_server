<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\V1Envelope;
use MyAdmin\VpsHost\Handlers\PhpsysinfoHandler;
use Workerman\Connection\AsyncTcpConnection;

/**
* v1 'telemetry.sysinfo' (§2.5) - request {host, params}, reply
* {host, params (echo), data (phpsysinfo result)} with enc:"gzip" on the
* envelope (v1 expresses the gzip+base64 via the envelope enc instead of the
* legacy inline base64(gzcompress()) field). Extends the legacy
* PhpsysinfoHandler so the TaskWorker phpsysinfo round-trip (runSysinfo)
* stays single-sourced; no 'for' routing field on the wire - the hub
* correlates the requester via the envelope id/re.
*/
class TelemetrySysinfoHandler extends PhpsysinfoHandler implements V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		$d = $envelope['data'];
		$params = $d['params'] ?? null;
		$host = $d['host'] ?? null;
		if (!is_array($params)) {
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'bad_request', 'telemetry.sysinfo requires params')));
			return;
		}
		$hubConn = $agent->conn;
		$this->runSysinfo($agent, $params, function ($task_result) use ($hubConn, $envelope, $params, $host) {
			$hubConn->send(json_encode(V1Envelope::reply($envelope['id'], [
				'host' => $host,
				'params' => $params,
				'data' => $task_result
			], true)));
		});
	}
}
