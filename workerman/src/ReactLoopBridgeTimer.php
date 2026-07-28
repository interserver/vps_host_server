<?php

namespace MyAdmin\VpsHost;

use React\EventLoop\TimerInterface;

/**
* Timer handle returned by ReactLoopBridge::addTimer()/addPeriodicTimer().
*
* Wraps the integer timer id of the underlying Workerman event loop so that
* React components (e.g. React\ChildProcess\Process, which cancels its own
* exit-poll timer via $loop->cancelTimer($timer)) get the TimerInterface
* object they expect.
*/
class ReactLoopBridgeTimer implements TimerInterface
{
	/** @var float */
	private $interval;

	/** @var callable */
	private $callback;

	/** @var bool */
	private $periodic;

	/** @var int|null Workerman event-loop timer id (null once fired/cancelled) */
	public $workermanTimerId = null;

	public function __construct(float $interval, callable $callback, bool $periodic)
	{
		$this->interval = $interval;
		$this->callback = $callback;
		$this->periodic = $periodic;
	}

	public function getInterval()
	{
		return $this->interval;
	}

	public function getCallback()
	{
		return $this->callback;
	}

	public function isPeriodic()
	{
		return $this->periodic;
	}
}
