<?php

namespace MyAdmin\VpsHost\Tests;

use MyAdmin\VpsHost\PTYPool;
use MyAdmin\VpsHost\PTYSession;
use Workerman\Worker;
use Workerman\Events\Select;

/**
* Empirical coverage of PTYPool (PROTOCOL_V1 §2.3 registry + reaper).
*
* Real ptys, real pids. Verifies both reaper trigger paths at the OS level -
* not just that entries vanish from the in-memory map, but that the underlying
* child processes are genuinely terminated (posix_kill($pid,0) === false):
*
*   - closeAll()  (simulates Agent::onClose() on hub disconnect): every child
*     is SIGKILLed and the map emptied.
*   - reap()      (simulates the periodic 'pty_reap' timer): a child that died
*     on its own (orphan) is detected via proc_get_status and removed, while a
*     still-alive sibling is left completely untouched.
*
* A live Workerman\Events\Select loop is installed into Worker::$globalEvent so
* PTYSession::close()'s guarded offReadable() path resolves; AgentTestCase also
* wires Worker::$outputStream so PTYPool's Worker::safeEcho() reaper log lines
* have somewhere to go (a bare CLI context with no outputStream would fatal in
* feof(null) inside safeEcho - a harness concern, not a pool bug).
*/
class PTYPoolTest extends AgentTestCase
{
	/** @var mixed */
	private $prevGlobalEvent;
	/** @var PTYPool */
	private $pool;

	protected function setUp(): void
	{
		parent::setUp();
		$ref = new \ReflectionProperty(Worker::class, 'globalEvent');
		$this->prevGlobalEvent = $ref->getValue();
		$ref->setValue(null, new Select());
		$this->pool = new PTYPool();
	}

	protected function tearDown(): void
	{
		$this->pool->closeAll();
		(new \ReflectionProperty(Worker::class, 'globalEvent'))->setValue(null, $this->prevGlobalEvent);
		parent::tearDown();
	}

	/** poll posix_kill($pid,0) until the pid is gone or timeout */
	private function assertPidGoneEventually(int $pid, string $msg): void
	{
		for ($i = 0; $i < 100; $i++) {
			if (!posix_kill($pid, 0)) {
				$this->assertFalse(posix_kill($pid, 0), $msg);
				return;
			}
			usleep(20000);
		}
		$this->fail($msg.' (pid '.$pid.' still alive after wait)');
	}

	public function testOpenRegistersAndGetReturnsSameSession(): void
	{
		$s = $this->pool->open('a', 'cat', 80, 24);
		$this->assertInstanceOf(PTYSession::class, $s);
		$this->assertSame($s, $this->pool->get('a'));
		$this->assertSame(1, $this->pool->count());
		$this->assertArrayHasKey('a', $this->pool->all());
		$this->assertNull($this->pool->get('nope'), 'unknown id -> null');
	}

	public function testDuplicateOpenThrowsAndLeavesOriginalUntouched(): void
	{
		$orig = $this->pool->open('dup', 'cat', 80, 24);
		$origPid = $orig->pid;
		try {
			$this->pool->open('dup', 'cat', 80, 24);
			$this->fail('duplicate pty_id must throw');
		} catch (\RuntimeException $e) {
			$this->assertStringContainsString('already in use', $e->getMessage());
		}
		// original must be the exact same object, still alive, count still 1.
		$this->assertSame($orig, $this->pool->get('dup'));
		$this->assertSame($origPid, $this->pool->get('dup')->pid, 'original child pid unchanged');
		$this->assertTrue($orig->isRunning(), 'original session must be untouched by the rejected duplicate');
		$this->assertSame(1, $this->pool->count());
	}

	public function testRemoveClosesChildAndDropsFromRegistry(): void
	{
		$s = $this->pool->open('rm', 'cat', 80, 24);
		$pid = $s->pid;
		$this->assertTrue(posix_kill($pid, 0));
		$this->pool->remove('rm', SIGTERM);
		$this->assertSame(0, $this->pool->count(), 'registry must drop the entry');
		$this->assertNull($this->pool->get('rm'));
		$this->assertPidGoneEventually($pid, 'remove() must actually terminate the child');
	}

	public function testRemoveUnknownIsNoOp(): void
	{
		$this->assertNull($this->pool->remove('ghost'), 'removing unknown id returns null, no crash');
	}

	public function testCloseAllTerminatesEveryChildAtOsLevelAndEmptiesPool(): void
	{
		// simulates Agent::onClose() -> closeAll()
		$pids = [];
		foreach (['x', 'y', 'z'] as $id) {
			$s = $this->pool->open($id, 'cat', 80, 24);
			$pids[$id] = $s->pid;
			$this->assertTrue(posix_kill($s->pid, 0), "child {$id} alive before closeAll");
		}
		$this->assertSame(3, $this->pool->count());

		$this->pool->closeAll();

		$this->assertSame(0, $this->pool->count(), 'pool must be empty after closeAll');
		foreach ($pids as $id => $pid) {
			$this->assertPidGoneEventually($pid, "closeAll must kill child {$id} at OS level");
		}
	}

	public function testReapRemovesOrphanAndLeavesLiveSiblingUntouched(): void
	{
		// 'dead' runs `true` and exits on its own; 'live' runs `cat` and stays up.
		$dead = $this->pool->open('dead', 'true', 80, 24);
		$live = $this->pool->open('live', 'cat', 80, 24);
		$deadPid = $dead->pid;
		$livePid = $live->pid;

		// wait for the orphan's child to actually exit before reaping.
		for ($i = 0; $i < 100 && $dead->isRunning(); $i++) {
			usleep(20000);
		}
		$this->assertFalse($dead->isRunning(), 'orphan child must have exited on its own');
		$this->assertTrue($live->isRunning(), 'sibling must still be running');
		$this->assertSame(2, $this->pool->count());

		$reaped = $this->pool->reap();

		$this->assertSame(1, $reaped, 'exactly one orphan must be reaped');
		$this->assertSame(1, $this->pool->count(), 'count must drop by one');
		$this->assertNull($this->pool->get('dead'), 'orphan must be removed from the registry');
		$this->assertSame($live, $this->pool->get('live'), 'live sibling must remain, same object');
		$this->assertTrue($live->isRunning(), 'live sibling must be untouched by reap');
		$this->assertTrue(posix_kill($livePid, 0), 'live sibling OS pid must still be alive after reap');
	}

	public function testReapDetectsExternallyKilledChild(): void
	{
		// kill the child OUTSIDE the pool's knowledge (SIGKILL direct to pid),
		// then reap() must notice via proc_get_status and drop the orphan.
		$s = $this->pool->open('killed', 'cat', 80, 24);
		$pid = $s->pid;
		posix_kill($pid, SIGKILL);
		for ($i = 0; $i < 100 && $s->isRunning(); $i++) {
			usleep(20000);
		}
		$this->assertFalse($s->isRunning(), 'externally-killed child must read as not running');

		$reaped = $this->pool->reap();
		$this->assertSame(1, $reaped);
		$this->assertSame(0, $this->pool->count());
		$this->assertNull($this->pool->get('killed'));
	}

	public function testReapReturnsZeroWhenAllHealthy(): void
	{
		$this->pool->open('h1', 'cat', 80, 24);
		$this->pool->open('h2', 'cat', 80, 24);
		$this->assertSame(0, $this->pool->reap(), 'no orphans -> reap returns 0');
		$this->assertSame(2, $this->pool->count());
	}
}
