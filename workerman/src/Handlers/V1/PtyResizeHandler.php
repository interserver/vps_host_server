<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use Workerman\Connection\AsyncTcpConnection;

/**
* v1 'pty.resize' (§2.3) - {pty_id, cols, rows}. No reply. Applies a REAL
* terminal resize (TIOCSWINSZ via `stty -F <slave>`, see PTYSession) so the
* child's ioctl-based winsize queries (readline, ncurses, etc.) reflect the
* new geometry and it receives SIGWINCH. Unknown pty_id is silently dropped
* (same racing-a-close posture as pty.data/pty.close - see PROTOCOL_V1 §6).
*/
class PtyResizeHandler implements V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		$d = $envelope['data'];
		$ptyId = $d['pty_id'] ?? null;
		if (!is_string($ptyId) || $ptyId === '' || !isset($d['cols']) || !isset($d['rows'])
			|| !is_numeric($d['cols']) || !is_numeric($d['rows'])) {
			return;
		}
		$session = $agent->ptys->get($ptyId);
		if ($session === null) {
			return;
		}
		$session->resize((int)$d['cols'], (int)$d['rows']);
	}
}
