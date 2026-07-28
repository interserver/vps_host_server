<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\V1Envelope;
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

/**
* v1 'config.token' (AUTH_DESIGN §3 push mechanism) - hub pushes a newly
* issued or rotated bearer token {host_id, token, issued_at}. The agent:
*
*   1. persists it via TokenStore (0600, atomic write) - this is ALSO the
*      bootstrap that flips the dual-running gate: this op is deliberately
*      accepted even while the connection is legacy-authenticated (a
*      token-less host receives its first token over the legacy link, and
*      only its NEXT connect performs the v1 auth.hello handshake; the
*      current connection's mode is never switched mid-stream);
*   2. acks with config.token_ack {host_id, token_fingerprint} (sha256
*      prefix) - the hub marks the token active only on ack.
*
* The plaintext token is never logged (only its fingerprint).
*/
class ConfigTokenHandler implements V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		$d = $envelope['data'];
		$token = $d['token'] ?? null;
		$hostId = isset($d['host_id']) && is_numeric($d['host_id']) ? (int)$d['host_id'] : null;
		if (!is_string($token) || $token === '') {
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'bad_request', 'config.token requires token')));
			return;
		}
		if (!$agent->v1->tokenStore()->save($token, $hostId)) {
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'internal', 'failed to persist token')));
			return;
		}
		$fingerprint = substr(hash('sha256', $token), 0, 16);
		Worker::safeEcho("[v1] config.token persisted (host_id ".var_export($hostId, true).", fingerprint {$fingerprint}); v1 mode active from next connect\n");
		$conn->send(json_encode(V1Envelope::request('config.token_ack', [
			'host_id' => $hostId !== null ? $hostId : (int)$agent->v1->tokenStore()->getHostId(),
			'token_fingerprint' => $fingerprint
		])));
	}
}
