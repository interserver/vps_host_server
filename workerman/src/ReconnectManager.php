<?php

namespace MyAdmin\VpsHost;

use Workerman\Worker;
use Workerman\Timer;

/**
* ReconnectManager - exponential-backoff reconnect scheduling for the agent's
* websocket link to the hub.
*
* Replaces the old onClose -> Worker::stopAll() behavior (which killed the whole
* process and relied on an external supervisor like systemd to restart it) with
* an in-process, self-healing reconnect loop:
*
*   - onClose / onError(CONNECT_FAIL) call scheduleReconnect(), which arms a
*     one-shot Workerman\Timer that runs the supplied reconnect-attempt callable
*     (Agent::reconnectToHub(): re-wire callbacks + AsyncTcpConnection::reconnect(0)).
*   - Backoff: delay = min(baseDelay * multiplier^attempts, maxDelay), with
*     +/- jitterRatio uniform jitter. Defaults: 2s base, x2 multiplier, 60s
*     cap, 20% jitter -> 2, 4, 8, 16, 32, 60, 60, ... seconds (jittered).
*   - confirmConnected() resets the attempt counter back to zero. The Agent
*     calls it from onMessage (first application frame received from the hub)
*     rather than from onConnect: a hub in a crash loop can accept the TCP/WS
*     connection and die before doing anything useful, and resetting only after
*     a real application frame round-trips prevents that from collapsing the
*     backoff into a fast reconnect storm.
*   - DESIGN CHOICE (flagged for review): there is NO max-attempts cutoff. This
*     is a long-running host agent; it retries forever at the capped delay so a
*     multi-hour hub outage never requires manual intervention on every host.
*/
class ReconnectManager
{
	/** @var float initial backoff delay in seconds */
	protected float $baseDelay;

	/** @var float maximum backoff delay in seconds (cap) */
	protected float $maxDelay;

	/** @var float backoff growth factor per failed attempt */
	protected float $multiplier;

	/** @var float uniform jitter as a fraction of the delay (0.2 = +/-20%) */
	protected float $jitterRatio;

	/** @var int consecutive failed/closed attempts since the last confirmed-good connection */
	protected int $attempts = 0;

	/** @var bool whether a reconnect timer is currently armed (guards double-scheduling when onError AND onClose both fire for the same drop) */
	protected bool $scheduled = false;

	public function __construct(float $baseDelay = 2.0, float $maxDelay = 60.0, float $multiplier = 2.0, float $jitterRatio = 0.2)
	{
		$this->baseDelay = $baseDelay;
		$this->maxDelay = $maxDelay;
		$this->multiplier = $multiplier;
		$this->jitterRatio = $jitterRatio;
	}

	/**
	* Arm a one-shot timer that re-attempts the hub connection after the current
	* backoff delay. Safe to call from both onClose and onError for the same
	* drop - the second call is a logged no-op.
	*
	* @param callable $attempt performs one reconnect attempt. IMPORTANT: in
	*        Workerman v5.2 TcpConnection::destroy() nulls onMessage/onClose/
	*        onError after emitting onClose ("Cleaning up the callback to avoid
	*        memory leaks", TcpConnection.php:1199), so a bare $conn->reconnect()
	*        would come back with NO callbacks wired. The callable must therefore
	*        re-wire all callbacks on the connection before calling reconnect(0)
	*        (see Agent::reconnectToHub()).
	* @param string $reason human-readable trigger, for the logs
	*/
	public function scheduleReconnect(callable $attempt, string $reason = ''): void
	{
		if ($this->scheduled) {
			Worker::safeEcho('[Reconnect] already scheduled, ignoring duplicate trigger ('.$reason.')'.PHP_EOL);
			return;
		}
		$delay = $this->nextDelay();
		$this->attempts++;
		$this->scheduled = true;
		Worker::safeEcho('[Reconnect] '.$reason.' - attempt #'.$this->attempts.' in '.$delay.'s'.PHP_EOL);
		// one-shot timer (persistent = false)
		Timer::add($delay, function () use ($attempt) {
			$this->scheduled = false;
			Worker::safeEcho('[Reconnect] attempting connection (attempt #'.$this->attempts.')'.PHP_EOL);
			$attempt();
		}, [], false);
	}

	/**
	* Called once the connection is confirmed live (an application frame has
	* round-tripped from the hub). Resets the backoff to the base delay.
	*/
	public function confirmConnected(): void
	{
		if ($this->attempts > 0) {
			Worker::safeEcho('[Reconnect] connection confirmed live after '.$this->attempts.' attempt(s), backoff reset'.PHP_EOL);
			$this->attempts = 0;
		}
	}

	/**
	* Compute the next jittered backoff delay without mutating state.
	* min(base * multiplier^attempts, max), then +/- jitterRatio uniform jitter
	* (still clamped to maxDelay, floored at 0.1s).
	*/
	public function nextDelay(): float
	{
		$delay = min($this->baseDelay * ($this->multiplier ** $this->attempts), $this->maxDelay);
		if ($this->jitterRatio > 0) {
			$jitter = $delay * $this->jitterRatio;
			$delay += (mt_rand(0, mt_getrandmax()) / mt_getrandmax()) * 2 * $jitter - $jitter;
		}
		return round(max(0.1, min($delay, $this->maxDelay)), 2);
	}

	/**
	* Number of consecutive failed/closed attempts since the last confirmed-good
	* connection (reset to 0 by confirmConnected()). Exposed for tests and logging;
	* note the backoff is uncapped in attempts (retries forever) but capped in delay
	* (maxDelay), so this counter can grow unbounded over a long hub outage without
	* ill effect - the delay math degrades gracefully past very high attempt counts.
	*/
	public function getAttempts(): int
	{
		return $this->attempts;
	}

	/**
	* Whether a reconnect timer is currently armed. The dedup flag this exposes is
	* what makes onError + onClose both firing for a single drop safe: the second
	* scheduleReconnect() sees this true and no-ops, so at most one reconnect timer
	* ever exists at a time.
	*/
	public function isScheduled(): bool
	{
		return $this->scheduled;
	}
}
