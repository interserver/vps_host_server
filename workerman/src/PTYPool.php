<?php

namespace MyAdmin\VpsHost;

use Workerman\Worker;

/**
* PTYPool - in-process registry of active PTYSession objects, keyed by
* pty_id. Mirrors the shape of the existing type=>handler registries in this
* repo (MessageDispatcher/V1MessageDispatcher/TaskRegistry - explicit map, no
* dynamic-property magic) but this state is deliberately NOT GlobalData: a
* pty child process is agent-local OS state (a real pid + open fds on THIS
* host), unlike $global->running/$global->ptys on the hub side, which
* coordinate across the hub's multiple worker processes for state that has
* no single-process owner. There is exactly one BusinessWorker-equivalent
* process per host agent holding these, so a plain in-process array is both
* sufficient and correct - a GlobalData entry would only add a network round
* trip for no coordination benefit and would NOT reflect real fd/pid
* ownership anyway (another process cannot read()/write() this process's
* pipe resources).
*
* Reaper (Phase 2 carried-forward item [2.4 LOW-1]: "No pty cleanup... Add a
* pty reaper / disconnect cleanup in Phase 3"). Two trigger points wired by
* the caller (Agent):
*   1. Agent::onClose() (hub disconnect) - closeAll() kills every open pty
*      immediately, so a dropped hub link can never leak a pty child past
*      reconnect (mirrors how ReconnectManager was wired into onClose in
*      step 3.4 - see BASELINE §9).
*   2. A periodic Workerman\Timer (wired via Agent::addTimer('pty_reap', ...))
*      calls reap(), which sweeps for sessions whose child has already died
*      on its own (crashed/exited without an explicit pty.close) and removes
*      them - so a hub-side bug that never sends pty.close cannot leak
*      terminated-but-unreaped entries in this map forever either.
*/
class PTYPool
{
	/** @var array<string, PTYSession> */
	private $sessions = [];

	/**
	* @param string $ptyId
	* @param string $command
	* @param int $cols
	* @param int $rows
	* @return PTYSession
	*/
	public function open($ptyId, $command, $cols = 80, $rows = 24)
	{
		if (isset($this->sessions[$ptyId])) {
			throw new \RuntimeException('pty_id already in use: '.$ptyId);
		}
		$session = new PTYSession($ptyId, $command, $cols, $rows);
		$this->sessions[$ptyId] = $session;
		return $session;
	}

	/**
	* @param string $ptyId
	* @return PTYSession|null
	*/
	public function get($ptyId)
	{
		return $this->sessions[$ptyId] ?? null;
	}

	/**
	* @return array<string, PTYSession>
	*/
	public function all()
	{
		return $this->sessions;
	}

	/**
	* close (if still open) and remove one session from the registry.
	*
	* @param string $ptyId
	* @param int $signal
	* @return int|null exit code, or null if it was already gone
	*/
	public function remove($ptyId, $signal = SIGTERM)
	{
		if (!isset($this->sessions[$ptyId])) {
			return null;
		}
		$exitCode = $this->sessions[$ptyId]->close($signal);
		unset($this->sessions[$ptyId]);
		return $exitCode;
	}

	/**
	* kill and remove every open session. Called from Agent::onClose() so a
	* hub disconnect can never leak a pty child process across a reconnect.
	*/
	public function closeAll()
	{
		foreach (array_keys($this->sessions) as $ptyId) {
			Worker::safeEcho("pty reaper: closing pty {$ptyId} on hub disconnect\n");
			$this->remove($ptyId, SIGKILL);
		}
	}

	/**
	* periodic sweep (wired to a Workerman\Timer): remove any session whose
	* child has already exited on its own (crashed, or exited without a
	* pty.close ever arriving) so the map cannot grow unbounded. Idempotent
	* and cheap - proc_get_status() per open session, no syscalls beyond that.
	*
	* @return int number of orphaned sessions reaped
	*/
	public function reap()
	{
		$reaped = 0;
		foreach ($this->sessions as $ptyId => $session) {
			if (!$session->isRunning()) {
				Worker::safeEcho("pty reaper: pty {$ptyId} process already exited, removing orphan\n");
				$this->remove($ptyId, SIGKILL);
				$reaped++;
			}
		}
		return $reaped;
	}

	/**
	* @return int count of currently tracked sessions
	*/
	public function count()
	{
		return count($this->sessions);
	}
}
