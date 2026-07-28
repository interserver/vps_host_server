<?php

namespace MyAdmin\VpsHost\Tests;

use MyAdmin\VpsHost\PTYSession;
use Workerman\Worker;
use Workerman\Events\Select;

/**
* Empirical, end-to-end coverage of PTYSession (PROTOCOL_V1 §2.3, agent-side).
*
* These are NOT mock-only tests: they spawn real proc_open() ptys (real
* /dev/pts/N slaves, real child pids) and drive a REAL Workerman\Events\Select
* event loop - the exact driver a production worker gets without ext-event/ev -
* installed into Worker::$globalEvent via reflection so PTYSession's
* Worker::getEventLoop()->onReadable()/offReadable() calls resolve to that live
* loop, just as they do inside a started worker. Mirrors the real-subprocess /
* real-Select-loop harness style established by ReactLoopBridgeTest (step 3.5).
*
* Proven empirically here (not merely "method returned true"):
*   - byte round-trip through the kernel pty (write() -> child -> readStream()),
*     including non-ASCII / control / high bytes (binary-safe path);
*   - resize() applies a genuine TIOCSWINSZ ioctl - verified by independently
*     reading `stty -F <slave> size` from the test process before/after;
*   - close() actually terminates the OS child (posix_kill($pid,0) === false).
*/
class PTYSessionTest extends AgentTestCase
{
	/** @var Select */
	private $event;
	/** @var mixed previous Worker::$globalEvent */
	private $prevGlobalEvent;
	/** @var array<int, PTYSession> sessions to force-close on tearDown */
	private array $toClose = [];

	protected function setUp(): void
	{
		parent::setUp();
		// install a real Workerman event loop so PTYSession's onReadable/
		// offReadable calls resolve exactly as in a started worker.
		$ref = new \ReflectionProperty(Worker::class, 'globalEvent');
		$this->prevGlobalEvent = $ref->getValue();
		$this->event = new Select();
		$ref->setValue(null, $this->event);
	}

	protected function tearDown(): void
	{
		foreach ($this->toClose as $s) {
			try {
				$s->close(SIGKILL);
			} catch (\Throwable $e) {
				// best-effort cleanup
			}
		}
		$this->toClose = [];
		(new \ReflectionProperty(Worker::class, 'globalEvent'))->setValue(null, $this->prevGlobalEvent);
		parent::tearDown();
	}

	private function track(PTYSession $s): PTYSession
	{
		$this->toClose[] = $s;
		return $s;
	}

	/** run the real event loop with a hard failsafe so a regression can't hang the suite */
	private function runLoopWithTimeout(float $seconds): void
	{
		$this->event->delay($seconds, function () {
			$this->event->stop();
		});
		$this->event->run();
	}

	public function testConstructSpawnsRealPtyChildWithSlavePath(): void
	{
		$s = $this->track(new PTYSession('con1', 'cat', 80, 24));
		$this->assertIsInt($s->pid, 'child pid must be an int');
		$this->assertTrue($s->isRunning(), 'freshly spawned cat must be running');
		$this->assertIsString($s->slavePath, 'slave path must resolve');
		$this->assertMatchesRegularExpression('#^/dev/pts/\d+$#', $s->slavePath, 'slave must be a real /dev/pts/N device');
		$this->assertTrue(posix_kill($s->pid, 0), 'OS pid must be alive');
	}

	public function testBinarySafeRoundTripThroughKernelPty(): void
	{
		// put the pty into raw mode so bytes echo back verbatim (no cooked-mode
		// ^A/^B expansion, no CR/LF translation) - this proves a genuinely
		// binary-safe path including control + high bytes.
		$s = $this->track(new PTYSession('rt1', 'stty raw -echo; cat', 80, 24));
		// give the `stty raw` a moment to take effect before writing
		$this->event->delay(0.15, function () use ($s) {
			$payload = "AB\x00\x01\x02\x1b\x7f\xfe\xffZ<END>";
			$s->write($payload);
		});

		$got = '';
		$s->watchStdout(
			function ($chunk) use (&$got) {
				$got .= $chunk;
				if (strpos($got, '<END>') !== false) {
					$this->event->stop();
				}
			},
			function () {
				$this->event->stop();
			}
		);
		$this->runLoopWithTimeout(4.0);

		$this->assertStringContainsString("\x00\x01\x02\x1b\x7f\xfe\xff", $got, 'control/high bytes must round-trip verbatim');
		$this->assertStringContainsString('<END>', $got, 'trailing marker must arrive');
		// exact verbatim echo in raw mode
		$this->assertStringContainsString("AB\x00\x01\x02\x1b\x7f\xfe\xffZ<END>", $got, 'raw-mode echo must be byte-identical');
	}

	public function testWatchStdoutEofFiresWhenChildExits(): void
	{
		// `true` exits immediately -> the read fd hits EOF -> onEof must fire once.
		$s = $this->track(new PTYSession('eof1', 'true', 80, 24));
		$eofCount = 0;
		$s->watchStdout(
			function ($chunk) {
				// ignore any stray output
			},
			function () use (&$eofCount) {
				$eofCount++;
				$this->event->stop();
			}
		);
		$this->runLoopWithTimeout(4.0);
		$this->assertSame(1, $eofCount, 'onEof must fire exactly once on child exit');
	}

	public function testResizeAppliesRealIoctlVerifiedFromSlave(): void
	{
		$s = $this->track(new PTYSession('rs1', 'cat', 40, 10));

		// independently read the geometry the kernel actually holds for the slave,
		// BEFORE our resize call. `stty size` prints "rows cols".
		$before = trim((string)shell_exec('stty -F '.escapeshellarg($s->slavePath).' size 2>&1'));
		$this->assertSame('10 40', $before, 'initial geometry must match constructor args (rows cols)');

		$ok = $s->resize(132, 50); // cols=132, rows=50
		$this->assertTrue($ok, 'resize() must report the ioctl succeeded');

		$after = trim((string)shell_exec('stty -F '.escapeshellarg($s->slavePath).' size 2>&1'));
		$this->assertSame('50 132', $after, 'kernel slave geometry must reflect the resize (rows cols) - real TIOCSWINSZ, not a no-op');

		// the object's own cached geometry must also be updated
		$this->assertSame(132, $s->cols);
		$this->assertSame(50, $s->rows);
	}

	public function testResizeReturnsFalseAfterClose(): void
	{
		$s = new PTYSession('rs2', 'cat', 80, 24);
		$s->close(SIGKILL);
		$this->assertFalse($s->resize(100, 40), 'resize on a closed session must return false');
	}

	public function testCloseTerminatesRealChildProcess(): void
	{
		$s = new PTYSession('cl1', 'cat', 80, 24);
		$pid = $s->pid;
		$this->assertTrue(posix_kill($pid, 0), 'pid must be alive before close');

		$code = $s->close(); // default SIGTERM
		// after proc_close the child is reaped; assert it is genuinely gone.
		$this->assertFalse($s->isRunning(), 'isRunning() must be false after close');

		// give the kernel a beat to finish reaping, then confirm at OS level.
		$gone = false;
		for ($i = 0; $i < 50; $i++) {
			if (!posix_kill($pid, 0)) {
				$gone = true;
				break;
			}
			usleep(20000);
		}
		$this->assertTrue($gone, 'child OS pid must be gone after close()');
	}

	public function testCloseIsIdempotent(): void
	{
		$s = new PTYSession('cl2', 'cat', 80, 24);
		$s->close();
		// second close must be a silent no-op returning null (guard on $this->closed)
		$this->assertNull($s->close(), 'second close() must be a no-op returning null');
	}

	public function testEmptyCommandSpawnsLoginShell(): void
	{
		// empty command => "exec $SHELL -l" / "exec /bin/bash -l" - must still be a live pty.
		$s = $this->track(new PTYSession('sh1', '', 80, 24));
		$this->assertTrue($s->isRunning(), 'empty command must spawn a (login-shell) pty child');
		$this->assertMatchesRegularExpression('#^/dev/pts/\d+$#', (string)$s->slavePath);
	}

	/**
	* locate the OS-level pid(s) actually running `sleep <marker>` - read from
	* /proc via ps, NOT from $session->pid, so these assertions are about what
	* the kernel is really running, independent of what proc_get_status()
	* reported.
	*
	* @return int[] pids
	*/
	private function findSleepPids(string $marker): array
	{
		$out = trim((string)shell_exec("ps -eo pid=,comm=,args= | awk '\$2==\"sleep\" && \$4==\"".$marker."\" {print \$1}'"));
		return $out === '' ? [] : array_map('intval', preg_split('/\s+/', $out));
	}

	/** poll until no `sleep <marker>` process remains (or timeout) */
	private function assertWorkloadGone(string $marker, string $message): void
	{
		$gone = false;
		for ($i = 0; $i < 100; $i++) {
			if ($this->findSleepPids($marker) === []) {
				$gone = true;
				break;
			}
			usleep(20000);
		}
		if (!$gone) {
			// don't leak the workload into the rest of the suite on failure
			foreach ($this->findSleepPids($marker) as $pid) {
				@posix_kill($pid, SIGKILL);
			}
		}
		$this->assertTrue($gone, $message);
	}

	public function testCloseKillsTheRealWorkloadNotJustAShellWrapper(): void
	{
		// REGRESSION (review BUG 1): proc_open(string) runs "/bin/sh -c <cmd>";
		// without the exec prefix, $s->pid is the sh wrapper's pid and
		// SIGTERM/SIGKILL to it leaves the real command orphaned. Prove the fix
		// at OS level: find the pid actually running the workload via ps (not
		// via $s->pid), close the session, assert THAT pid is gone.
		$marker = '987654';
		$s = new PTYSession('realkill1', 'sleep '.$marker, 80, 24);
		usleep(300000); // let sh exec the workload
		$real = $this->findSleepPids($marker);
		$this->assertCount(1, $real, 'exactly one real workload process must be running');
		// with the exec prefix the wrapper pid IS the workload pid
		$this->assertSame($s->pid, $real[0], 'session pid must be the REAL workload pid (exec collapsed the sh wrapper)');
		$s->close();
		$this->assertWorkloadGone($marker, 'real workload must be dead after close() - no orphan');
	}

	public function testCloseKillsRealWorkloadOfACompoundCommand(): void
	{
		// compound commands ("a; b") cannot take a bare `exec` prefix; they are
		// nested through `exec /bin/bash -c <cmd>` and bash tail-execs the final
		// command - so the real workload must STILL die on close(), and the
		// compound semantics must be preserved (both parts run).
		$marker = '987655';
		$s = new PTYSession('realkill2', 'stty raw -echo; sleep '.$marker, 80, 24);
		usleep(400000); // let stty finish and bash tail-exec the sleep
		$this->assertCount(1, $this->findSleepPids($marker), 'compound command final workload must actually be running');
		$this->assertTrue($s->isRunning());
		$s->close();
		$this->assertWorkloadGone($marker, 'compound-command workload must be dead after close() - no orphan');
	}

	public function testCloseEscalatesToSigkillForSigtermIgnoringWorkload(): void
	{
		// REGRESSION (review BUG 3): a workload that ignores SIGTERM must not
		// hang close() (which ends in a blocking proc_close()/wait4()) - close()
		// must escalate to SIGKILL after its bounded grace period and return.
		// `trap '' TERM` set before the tail-exec is inherited across execve(),
		// so the sleep really does ignore SIGTERM.
		$marker = '987656';
		$s = new PTYSession('realkill3', "trap '' TERM; sleep ".$marker, 80, 24);
		usleep(400000);
		$this->assertCount(1, $this->findSleepPids($marker), 'SIGTERM-ignoring workload must be running');
		$start = microtime(true);
		$s->close(); // default SIGTERM -> ignored -> must escalate to SIGKILL
		$elapsed = microtime(true) - $start;
		$this->assertLessThan(3.0, $elapsed, 'close() must return within its bounded grace window, never hang the loop');
		$this->assertWorkloadGone($marker, 'SIGTERM-ignoring workload must be SIGKILLed by the escalation path');
	}
}
