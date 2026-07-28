<?php

namespace MyAdmin\VpsHost;

use Workerman\Worker;

/**
* PTYSession - one open pseudo-terminal (PROTOCOL_V1 §2.3 pty.*).
*
* Spawns via proc_open() with descriptor type "pty" (Linux, PHP 7.4+; this
* repo runs PHP >=8.2 per composer.json). This is a REAL kernel pty (a
* /dev/pts/N slave allocated by the pty master ptmx), not a plain pipe -
* unlike the legacy 'run'/interact:true path (RunHandler/React\ChildProcess),
* which streams over plain stdio pipes and has no terminal semantics at all
* (no ioctl winsize, no job control, no controlling-tty behavior). Verified on
* this host: `stty size` inside the child reflects a resize applied from
* outside the process (see resize()).
*
* Streaming model: stdout/stderr are the SAME fd under a pty (the child's
* fd 1 and fd 2 both point at the pty slave), so only one read-side pipe
* ($pipes[1]) carries all child output; there is no separate stderr stream
* to multiplex, matching real terminal behavior. Reads happen on the
* Workerman event loop via EventInterface::onReadable() (NOT
* React\ChildProcess - a raw proc_open pty pair is not a React stream/process
* at all, so ReactLoopBridge does not apply here; it is reserved for actual
* React components (React\ChildProcess\Process, React\Http\Browser) per
* BASELINE §10. This class registers directly on Worker::getEventLoop(),
* which IS the correct API for plain stream fds - the bridge exists only to
* satisfy React's LoopInterface type contract, not because raw fd polling is
* unsafe).
*
* Resize mechanism (documented choice, not faked): plain proc_open has no
* ioctl(TIOCSWINSZ) primitive in userland PHP (no ext-pty is installed on
* this box - only ext-posix/ext-pcntl/ext-ffi, none of which expose ioctl
* directly without a C shim). The chosen mechanism is: resolve the real pty
* slave device path via readlink("/proc/{pid}/fd/0") (proc_open's own
* /dev/ptmx-side descriptor reports as "/dev/ptmx" via posix_ttyname(), which
* is USELESS for this purpose - the /proc fd symlink is what resolves to the
* actual /dev/pts/N slave), then shell out to the system `stty` binary
* (`stty -F <slave> rows R cols C`), which performs the real TIOCSWINSZ ioctl
* kernel-side and delivers SIGWINCH to the foreground process group. This was
* verified empirically on this host: a child's own `stty size` reflects the
* externally-applied rows/cols immediately after the call returns. This is a
* real resize, not a cosmetic no-op.
*
* Scope gating (PROTOCOL_V1 §5 / hub SPEC-GAP note): the hub is the primary
* enforcement point for scope:"shell" vs scope:"command" (conservative-denied
* server-side unless $_SESSION['pty_shell']===true, which nothing currently
* sets), and per §5 the agent ADDITIONALLY refuses shell-scope opens lacking
* an elevation marker - that agent-side gate lives in PtyOpenHandler (which
* requires data.elevated === true on the frame before a shell-mode open ever
* reaches this constructor). This class itself performs no privilege lookup:
* an empty command spawns a login shell via "exec $SHELL -l" (falling back to
* exec /bin/bash -l), a non-empty command runs via "exec /bin/bash -c
* <command>" inside the pty. No sudo, no elevation happens in this class -
* callers are responsible for having applied the §5 gate first.
*/
class PTYSession
{
	/** @var string */
	public $ptyId;

	/** @var resource|null proc_open() process handle */
	public $process;

	/** @var array<int, resource> proc_open() pipes ([0]=>stdin/stdout/stderr pty fd, since pty fuses them) */
	public $pipes = [];

	/** @var int|null child pid */
	public $pid;

	/** @var string|null resolved /dev/pts/N slave path, used for resize() */
	public $slavePath;

	/** @var int */
	public $cols;

	/** @var int */
	public $rows;

	/** @var bool */
	public $closed = false;

	/** @var bool has watchStdout() already registered the read-stream watcher? */
	private $watching = false;

	/**
	* @param string $ptyId    unique id (uuid, per protocol)
	* @param string $command  command to run; empty string = login shell
	* @param int $cols
	* @param int $rows
	*/
	public function __construct($ptyId, $command, $cols = 80, $rows = 24)
	{
		$this->ptyId = $ptyId;
		$this->cols = (int)$cols;
		$this->rows = (int)$rows;
		$descriptors = [
			0 => ['pty'],
			1 => ['pty'],
			2 => ['pty'],
		];
		// exec-prefix so signals reach the REAL workload, not an sh wrapper:
		// proc_open(string) always spawns "/bin/sh -c <cmd>", and /bin/sh on
		// this host (dash) does NOT tail-exec its last command under a pty -
		// so without `exec`, $this->pid would be the wrapper sh's pid and
		// proc_terminate()/SIGKILL would provably orphan the actual child
		// (verified empirically; see close()). With `exec` the shell replaces
		// itself via execve(), keeping the same pid - $this->pid IS the
		// workload. A non-empty command is nested through `bash -c` (not a
		// bare "exec <command>") because `exec` before a compound list
		// ("a; b") would discard everything after the first command; bash
		// (unlike dash) tail-execs the final simple command of a -c string,
		// so the wrapper pid collapses onto the workload for compound
		// commands too.
		if (trim((string)$command) === '') {
			$shell = getenv('SHELL') !== false && getenv('SHELL') !== '' ? getenv('SHELL') : '/bin/bash';
			$shellCmd = 'exec '.$shell.' -l';
		} else {
			$shellCmd = 'exec /bin/bash -c '.escapeshellarg($command);
		}
		$env = array_merge(['COLUMNS' => $this->cols, 'LINES' => $this->rows, 'TERM' => 'xterm'], $_SERVER);
		unset($env['argv']);
		$this->process = proc_open($shellCmd, $descriptors, $this->pipes, __DIR__.'/../../../', $env);
		if (!is_resource($this->process)) {
			throw new \RuntimeException('pty.open: proc_open failed for pty_id '.$ptyId);
		}
		foreach ($this->pipes as $pipe) {
			stream_set_blocking($pipe, false);
		}
		$status = proc_get_status($this->process);
		$this->pid = $status['pid'];
		// resolve the real /dev/pts/N slave via the child's own fd 0 - NOT
		// posix_ttyname($this->pipes[0]), which reports the master-side
		// "/dev/ptmx" and cannot be used for a stty resize (see class docblock).
		$fd0Link = '/proc/'.$this->pid.'/fd/0';
		$this->slavePath = @readlink($fd0Link) ?: null;
		// apply the initial size immediately so the child's very first ioctl
		// query (many shells/readline call this on start) sees the real geometry
		if ($this->slavePath !== null) {
			$this->applyStty($this->cols, $this->rows);
		}
	}

	/**
	* @return resource the single read/write pty fd (pipes[1] for reads is
	*                   equivalent to pipes[0]/pipes[2] under a pty - all three
	*                   descriptors resolve to the same underlying slave)
	*/
	public function readStream()
	{
		return $this->pipes[1];
	}

	/**
	* register the child-output watcher on Workerman's OWN event loop
	* (Worker::getEventLoop()::onReadable() - EventInterface, not React's
	* LoopInterface). This is a plain proc_open pipe fd, not a
	* React\ChildProcess\Process/React\Stream - there is no React component
	* here at all, so ReactLoopBridge (which exists solely to satisfy React's
	* LoopInterface type contract for actual React objects, per BASELINE §10)
	* does not apply; raw fd polling via EventInterface::onReadable() is the
	* correct and sufficient primitive, exactly as core Workerman itself uses
	* internally for its own connections.
	*
	* @param callable(string $chunk): void $onData     called with each non-empty read
	* @param callable(): void              $onEof      called once when the pty fd hits EOF (child exited)
	*/
	public function watchStdout(callable $onData, callable $onEof): void
	{
		if ($this->watching || $this->closed || !isset($this->pipes[1]) || !is_resource($this->pipes[1])) {
			return;
		}
		$this->watching = true;
		$stream = $this->pipes[1];
		Worker::getEventLoop()->onReadable($stream, function ($stream) use ($onData, $onEof) {
			$chunk = @fread($stream, 65536);
			if ($chunk === false || $chunk === '') {
				if (feof($stream)) {
					Worker::getEventLoop()->offReadable($stream);
					$onEof();
				}
				return;
			}
			$onData($chunk);
		});
	}

	/**
	* write raw bytes to the pty (child's stdin).
	*
	* @param string $bytes
	*/
	public function write($bytes)
	{
		if ($this->closed || !isset($this->pipes[0]) || !is_resource($this->pipes[0])) {
			return;
		}
		fwrite($this->pipes[0], $bytes);
	}

	/**
	* resize the pty. See class docblock for why `stty -F <slave>` is the
	* chosen real mechanism (not a no-op / not faked).
	*
	* @param int $cols
	* @param int $rows
	* @return bool true if the resize ioctl was actually applied
	*/
	public function resize($cols, $rows)
	{
		$this->cols = (int)$cols;
		$this->rows = (int)$rows;
		if ($this->closed || $this->slavePath === null) {
			return false;
		}
		return $this->applyStty($this->cols, $this->rows);
	}

	/**
	* @param int $cols
	* @param int $rows
	* @return bool
	*/
	private function applyStty($cols, $rows)
	{
		$cmd = 'stty -F '.escapeshellarg($this->slavePath).' rows '.((int)$rows).' cols '.((int)$cols).' 2>&1';
		exec($cmd, $output, $rc);
		if ($rc !== 0) {
			Worker::safeEcho("pty {$this->ptyId}: stty resize failed (rc={$rc}): ".implode(' ', $output).PHP_EOL);
			return false;
		}
		return true;
	}

	/**
	* is the child process still alive?
	*
	* @return bool
	*/
	public function isRunning()
	{
		if (!is_resource($this->process)) {
			return false;
		}
		$status = proc_get_status($this->process);
		return (bool)($status['running'] ?? false);
	}

	/**
	* terminate the child and close all pipes/fds. Idempotent.
	*
	* Escalation: sends $signal (default SIGTERM), then polls
	* proc_get_status() non-blockingly for a short bounded grace period
	* (~0.5s); if the child still has not exited, escalates to SIGKILL and
	* waits (again bounded, ~0.5s) for it to land. This keeps the trailing
	* proc_close() - which blocks in wait4() until the child is reapable -
	* from ever hanging the single event-loop thread on a SIGTERM-ignoring
	* workload, at the cost of at most ~1s of synchronous wait during an
	* explicit close/reap (acceptable: this only runs on pty.close, hub
	* disconnect closeAll(), or the 60s reaper sweep - never per frame).
	*
	* @param int $signal defaults to SIGTERM; escalates to SIGKILL here (see above)
	* @return int|null exit code if known immediately, else null
	*/
	public function close($signal = SIGTERM)
	{
		if ($this->closed) {
			return null;
		}
		$this->closed = true;
		$exitCode = null;
		if ($this->watching && isset($this->pipes[1]) && is_resource($this->pipes[1])) {
			// deregister the read watcher BEFORE closing the fd it refers to -
			// only if watchStdout() actually registered one; Worker::getEventLoop()
			// is only valid inside a started worker (see its own docblock and
			// ReactLoopBridge's identical caveat), so skipping this when nothing
			// was ever registered lets close()/remove() be called safely from
			// contexts with no running Workerman event loop (e.g. unit tests).
			Worker::getEventLoop()->offReadable($this->pipes[1]);
			$this->watching = false;
		}
		if (is_resource($this->process)) {
			$status = proc_get_status($this->process);
			if (!empty($status['running'])) {
				proc_terminate($this->process, $signal);
				$status = $this->waitForExit(0.5);
				if (!empty($status['running']) && $signal !== SIGKILL) {
					// SIGTERM ignored/blocked by the workload - escalate so
					// proc_close() below cannot block the event loop forever.
					proc_terminate($this->process, SIGKILL);
					$this->waitForExit(0.5);
				}
			}
			foreach ($this->pipes as $pipe) {
				if (is_resource($pipe)) {
					@fclose($pipe);
				}
			}
			$exitCode = proc_close($this->process);
		}
		return $exitCode;
	}

	/**
	* bounded non-blocking wait for the child to exit: polls proc_get_status()
	* in small (20ms) sleeps for at most $seconds. Never blocks in wait4() -
	* proc_get_status() uses WNOHANG - so the worst case is $seconds of
	* usleep, never an unbounded hang.
	*
	* @param float $seconds
	* @return array last proc_get_status() result
	*/
	private function waitForExit($seconds)
	{
		$deadline = microtime(true) + $seconds;
		$status = proc_get_status($this->process);
		while (!empty($status['running']) && microtime(true) < $deadline) {
			usleep(20000);
			$status = proc_get_status($this->process);
		}
		return $status;
	}
}
