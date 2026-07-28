<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use Workerman\Connection\AsyncTcpConnection;

/**
* v1 'pty.close' (§2.3) - {pty_id, code?}. No reply. Either party (hub
* relaying an admin close, or a self-inflicted close after the read-loop saw
* EOF) may send this; terminates the child (SIGTERM first via
* PTYSession::close(), matching the legacy StopRunHandler's kill posture)
* and removes it from the pool, un-registering its read-stream listener so
* the event loop is not left polling a dead fd.
*
* Idempotent: closing an already-closed/unknown pty_id is a silent no-op -
* both sides may race to close the same session (hub-initiated close vs.
* this agent's own EOF-triggered pty.close, emitted from the onEof callback
* PtyOpenHandler passes to PTYSession::watchStdout()).
*/
class PtyCloseHandler implements V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		$d = $envelope['data'];
		$ptyId = $d['pty_id'] ?? null;
		if (!is_string($ptyId) || $ptyId === '') {
			return;
		}
		$session = $agent->ptys->get($ptyId);
		if ($session === null) {
			return;
		}
		$agent->ptys->remove($ptyId, SIGTERM);
	}
}
