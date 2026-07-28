<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\V1Envelope;
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

/**
* v1 'pty.open' (§2.3) - {pty_id, scope?, command?, cols?, rows?, env?}.
* Allocates a REAL pseudo-terminal (proc_open with "pty" descriptors - see
* PTYSession docblock) via $agent->ptys (PTYPool) and registers the pty's
* read-side fd on the Workerman event loop so output streams to the hub as
* pty.data frames (base64, per §2.3) as it arrives.
*
* Scope gating (PROTOCOL_V1 §5): the hub is the PRIMARY enforcement point
* for scope:"shell" vs scope:"command" (conservative-denied server-side
* unless $_SESSION['pty_shell']===true, which nothing currently sets), but
* §5 also requires the agent to ADDITIONALLY refuse "scope:'shell' from a
* hub message lacking the elevation marker" - defense in depth, so a
* compromised/buggy hub cannot hand out login shells. That agent-side gate
* is enforced HERE and fails CLOSED: any open that would spawn a login shell
* (scope === "shell", or an empty/absent `command`, which is the same thing
* per §2.3) is refused with `forbidden` unless the frame carries
* `data.elevated === true`. The hub's elevation marker is a server-side
* session flag that does not travel on the wire by itself, and §2.3 defines
* no dedicated wire field for it - so `data.elevated === true` is this
* agent's narrowest safe interpretation of "the elevation marker", defaulting
* to refuse when absent (mirroring the hub's own conservative-deny posture
* from step 2.4). scope:"command" opens with a real non-empty `command` are
* unaffected and need no marker. A non-empty `command` runs exactly that
* command in the pty (via PTYSession's exec-prefixed bash -c wrapper).
*
* `env` (client-supplied extra environment) is intentionally NOT merged in -
* mirrors the hub's own "env dropped" decision documented in its
* handlePtyOpen (arbitrary attacker-controlled LD_PRELOAD/PATH/BASH_ENV must
* not reach the host); only COLUMNS/LINES/TERM plus $_SERVER are set (see
* PTYSession).
*/
class PtyOpenHandler implements V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		$d = $envelope['data'];
		$ptyId = $d['pty_id'] ?? null;
		if (!is_string($ptyId) || $ptyId === '') {
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'bad_request', 'pty.open requires pty_id')));
			return;
		}
		if ($agent->ptys->get($ptyId) !== null) {
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'bad_request', 'pty_id already in use')));
			return;
		}
		$command = isset($d['command']) && is_string($d['command']) ? $d['command'] : '';
		// §5 agent-side scope gate (fail CLOSED): a shell-mode open - either an
		// explicit scope:"shell" or an empty/absent command (which PTYSession
		// would turn into a login shell) - is refused unless the frame carries
		// the elevation marker data.elevated === true. See class docblock.
		$scope = isset($d['scope']) && is_string($d['scope']) ? $d['scope'] : 'command';
		if ($scope === 'shell' || trim($command) === '') {
			if (($d['elevated'] ?? null) !== true) {
				$conn->send(json_encode(V1Envelope::error($envelope['id'], 'forbidden', 'pty.open scope "shell" requires elevation (data.elevated === true)')));
				return;
			}
		}
		$cols = isset($d['cols']) && is_numeric($d['cols']) ? (int)$d['cols'] : 80;
		$rows = isset($d['rows']) && is_numeric($d['rows']) ? (int)$d['rows'] : 24;
		try {
			$session = $agent->ptys->open($ptyId, $command, $cols, $rows);
		} catch (\Throwable $e) {
			Worker::safeEcho("pty.open failed for {$ptyId}: {$e->getMessage()}\n");
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'internal', 'failed to allocate pty')));
			return;
		}
		// stream child output to the hub as it arrives; on EOF (child exited)
		// reap the session and notify via pty.close. watchStdout() registers
		// on Workerman's own EventInterface, not React's LoopInterface - see
		// PTYSession docblock for why ReactLoopBridge does not apply here.
		$session->watchStdout(
			function ($chunk) use ($conn, $ptyId) {
				$conn->send(json_encode(V1Envelope::request('pty.data', [
					'pty_id' => $ptyId,
					'data' => base64_encode($chunk),
				])));
			},
			function () use ($agent, $conn, $ptyId) {
				$exitCode = $agent->ptys->remove($ptyId);
				$conn->send(json_encode(V1Envelope::request('pty.close', array_filter([
					'pty_id' => $ptyId,
					'code' => $exitCode,
				], function ($v) {
					return $v !== null;
				}))));
			}
		);
		$conn->send(json_encode(V1Envelope::reply($envelope['id'], ['pty_id' => $ptyId])));
	}
}
