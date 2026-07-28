<?php

namespace MyAdmin\VpsHost;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use Workerman\Events\EventInterface;
use Workerman\Worker;

/**
* Adapter exposing Workerman v5's running event loop (Workerman\Events\EventInterface —
* Select/Event/Ev/Fiber drivers) through React's LoopInterface, so React components
* (React\ChildProcess\Process, React\Http\Browser, react/stream, ...) can register
* their streams and timers on the SAME loop Workerman is already running.
*
* Why this exists: under Workerman v4 the legacy code passed Worker::getEventLoop()
* straight into React\ChildProcess\Process::start(). Under Workerman v5.2 that object
* no longer satisfies React's type check — Process::start() throws
* InvalidArgumentException('Argument #1 ($loop) expected null|React\EventLoop\LoopInterface').
* Passing null is no better: React then falls back to its own global Loop::get()
* singleton, which is never run while Workerman's loop is blocking, so child output
* and exit events only flush at worker shutdown (verified empirically). This bridge
* delegates every React loop operation to Workerman's live loop instead.
*
* Usage (from inside a running worker): $loop = ReactLoopBridge::instance();
*
* Notes:
* - run()/stop() are intentional no-ops: Workerman owns the loop lifecycle.
* - addSignal() delegates to onSignal(), which supports a single handler per signal
*   in Workerman — fine for React components (none of the ones used here register
*   signals), but do not use it to stack multiple listeners on one signal.
*/
class ReactLoopBridge implements LoopInterface
{
	/** @var self|null */
	private static $instance = null;

	/** @var EventInterface */
	private $event;

	public function __construct(EventInterface $event)
	{
		$this->event = $event;
	}

	/**
	* Bridge wrapping the current worker's running event loop.
	* Only valid inside a started worker process (after onWorkerStart).
	*/
	public static function instance(): self
	{
		$event = Worker::getEventLoop();
		if (self::$instance === null || self::$instance->event !== $event) {
			self::$instance = new self($event);
		}
		return self::$instance;
	}

	public function addReadStream($stream, $listener)
	{
		$this->event->onReadable($stream, $listener);
	}

	public function addWriteStream($stream, $listener)
	{
		$this->event->onWritable($stream, $listener);
	}

	public function removeReadStream($stream)
	{
		$this->event->offReadable($stream);
	}

	public function removeWriteStream($stream)
	{
		$this->event->offWritable($stream);
	}

	public function addTimer($interval, $callback)
	{
		$timer = new ReactLoopBridgeTimer((float)$interval, $callback, false);
		$timer->workermanTimerId = $this->event->delay((float)$interval, function () use ($timer) {
			$timer->workermanTimerId = null;
			($timer->getCallback())($timer);
		});
		return $timer;
	}

	public function addPeriodicTimer($interval, $callback)
	{
		$timer = new ReactLoopBridgeTimer((float)$interval, $callback, true);
		$timer->workermanTimerId = $this->event->repeat((float)$interval, function () use ($timer) {
			($timer->getCallback())($timer);
		});
		return $timer;
	}

	public function cancelTimer(TimerInterface $timer)
	{
		if (!$timer instanceof ReactLoopBridgeTimer || $timer->workermanTimerId === null) {
			return;
		}
		if ($timer->isPeriodic()) {
			$this->event->offRepeat($timer->workermanTimerId);
		} else {
			$this->event->offDelay($timer->workermanTimerId);
		}
		$timer->workermanTimerId = null;
	}

	public function futureTick($listener)
	{
		$this->event->delay(0.0, $listener);
	}

	public function addSignal($signal, $listener)
	{
		$this->event->onSignal($signal, $listener);
	}

	public function removeSignal($signal, $listener)
	{
		$this->event->offSignal($signal);
	}

	public function run()
	{
		// no-op: Workerman drives the underlying loop
	}

	public function stop()
	{
		// no-op: stopping the shared loop would kill the whole worker
	}
}
