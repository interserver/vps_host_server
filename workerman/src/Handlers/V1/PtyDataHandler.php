<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use Workerman\Connection\AsyncTcpConnection;

/**
* v1 'pty.data' (§2.3) - {pty_id, data (base64)}. Full-duplex, no reply.
* Decodes the base64 payload and writes the raw bytes to the pty's stdin.
* Silently drops data for an unknown/already-closed pty_id (mirrors the
* hub's own "unknown pty_id silently dropped (data racing a close)" note in
* PROTOCOL_V1.md §6 - a close in flight must not surface as a spurious error
* to either party).
*/
class PtyDataHandler implements V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		$d = $envelope['data'];
		$ptyId = $d['pty_id'] ?? null;
		if (!is_string($ptyId) || $ptyId === '' || !isset($d['data']) || !is_string($d['data'])) {
			return;
		}
		$session = $agent->ptys->get($ptyId);
		if ($session === null) {
			return;
		}
		$bytes = base64_decode($d['data'], true);
		if ($bytes === false) {
			return;
		}
		$session->write($bytes);
	}
}
