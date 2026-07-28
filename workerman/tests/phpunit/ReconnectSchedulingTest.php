<?php

namespace MyAdmin\VpsHost\Tests;

use MyAdmin\VpsHost\ReconnectManager;
use Workerman\Timer;

/**
* Step 3.4: scheduleReconnect() accounting + the `scheduled` dedup flag.
*
* scheduleReconnect() arms a one-shot Workerman\Timer. We don't run the event loop
* here; we assert the *observable state* it mutates synchronously (attempts counter,
* scheduled flag, log output) and that a second call for the same drop is deduped.
*
* Timer::$tasks is drained in tearDown so a queued (never-fired) timer from one test
* cannot leak into another.
*/
class ReconnectSchedulingTest extends AgentTestCase
{
	/** @var mixed previous Timer $event backend, restored in tearDown */
	private $prevEvent;

	protected function setUp(): void
	{
		parent::setUp();
		// Timer::add() (used by scheduleReconnect) refuses to run unless at least one
		// Worker is registered, and it uses the in-array $tasks scheduler only when no
		// event backend is set. Register a throwaway Worker and null out any leftover
		// $event backend so scheduled timers land in Timer::$tasks (which we introspect).
		new \Workerman\Worker();
		$eventProp = new \ReflectionProperty(Timer::class, 'event');
		$eventProp->setAccessible(true);
		$this->prevEvent = $eventProp->getValue();
		$eventProp->setValue(null, null);
	}

	protected function tearDown(): void
	{
		// scheduleReconnect() queues a real Timer we never let fire - clear it so the
		// static Timer state does not bleed across tests.
		Timer::delAll();
		$eventProp = new \ReflectionProperty(Timer::class, 'event');
		$eventProp->setAccessible(true);
		$eventProp->setValue(null, $this->prevEvent);
		parent::tearDown();
	}

	public function testScheduleReconnectIncrementsAttemptsAndSetsFlag(): void
	{
		$rm = new ReconnectManager();
		$this->assertSame(0, $rm->getAttempts());
		$this->assertFalse($rm->isScheduled());

		$rm->scheduleReconnect(function () {}, 'test drop');

		$this->assertSame(1, $rm->getAttempts(), 'one drop -> one attempt counted');
		$this->assertTrue($rm->isScheduled(), 'a reconnect timer is now armed');
	}

	public function testScheduleReconnectLogsAttemptLine(): void
	{
		$rm = new ReconnectManager();
		$rm->scheduleReconnect(function () {}, 'connection closed');
		$out = $this->capturedOutput();
		$this->assertStringContainsString('[Reconnect] connection closed - attempt #1', $out);
	}

	/**
	* The core dedup guarantee: when BOTH onError(CONNECT_FAIL) and onClose fire for
	* the SAME drop (proven empirically to both happen on a refused connect), only
	* ONE reconnect is scheduled. The second call is a logged no-op: attempts stays
	* at 1 and the supplied callable is NOT (additionally) queued.
	*/
	public function testDuplicateScheduleIsDedupedWhileFlagSet(): void
	{
		$rm = new ReconnectManager();

		$firstCalls = 0;
		$secondCalls = 0;
		// first trigger (e.g. from onError CONNECT_FAIL)
		$rm->scheduleReconnect(function () use (&$firstCalls) { $firstCalls++; }, 'connect failed');
		// second trigger for the same drop (e.g. from onClose via destroy())
		$rm->scheduleReconnect(function () use (&$secondCalls) { $secondCalls++; }, 'connection closed');

		$this->assertSame(1, $rm->getAttempts(), 'duplicate trigger must NOT double-count attempts');
		$this->assertTrue($rm->isScheduled());

		$out = $this->capturedOutput();
		$this->assertStringContainsString('already scheduled, ignoring duplicate trigger (connection closed)', $out);
		// only one "attempt #1 in" scheduling line total
		$this->assertSame(1, substr_count($out, 'attempt #1 in'));
	}

	/**
	* Once the armed timer fires it clears the flag and runs the attempt callable;
	* a subsequent drop is then allowed to schedule again. We simulate the timer
	* firing by invoking the queued Timer task directly (no event loop needed).
	*/
	public function testFlagClearsWhenTimerFiresThenCanRescheduleAndAttemptRuns(): void
	{
		$rm = new ReconnectManager(0.01, 0.02, 2.0, 0.0);

		$attemptRan = 0;
		$rm->scheduleReconnect(function () use (&$attemptRan) { $attemptRan++; }, 'first drop');
		$this->assertTrue($rm->isScheduled());

		// find and invoke the queued one-shot timer callback (Timer::$tasks is protected)
		$this->fireQueuedTimers();

		$this->assertSame(1, $attemptRan, 'the reconnect attempt callable must run when the timer fires');
		$this->assertFalse($rm->isScheduled(), 'flag must clear once the timer fires');

		// a new drop after the attempt can schedule again (attempts now 2)
		$rm->scheduleReconnect(function () {}, 'second drop');
		$this->assertSame(2, $rm->getAttempts());
		$this->assertTrue($rm->isScheduled());
	}

	/** invoke every queued Workerman Timer task callback once, in id order */
	private function fireQueuedTimers(): void
	{
		$tasksProp = new \ReflectionProperty(Timer::class, 'tasks');
		$tasksProp->setAccessible(true);
		$tasks = $tasksProp->getValue();
		if (!is_array($tasks)) {
			$this->fail('Timer::$tasks not an array - cannot simulate timer firing');
		}
		foreach ($tasks as $runTime => $bucket) {
			foreach ($bucket as $timerId => $task) {
				// task shape: [callback, args, persistent, interval]
				[$cb, $args] = $task;
				$cb(...$args);
			}
		}
	}
}
