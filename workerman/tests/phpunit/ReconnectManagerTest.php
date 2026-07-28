<?php

namespace MyAdmin\VpsHost\Tests;

use MyAdmin\VpsHost\ReconnectManager;

/**
* Step 3.4: ReconnectManager backoff math in isolation.
*
* Verifies the exponential-backoff progression, the ±jitter bounds, the cap,
* the confirmConnected() reset-to-base behavior, and the `scheduled` dedup flag
* accounting - all without a live Workerman event loop.
*
* nextDelay() is pure/non-mutating (it only reads $attempts), and scheduleReconnect()
* is the one that increments $attempts and arms a Timer. We drive nextDelay() directly
* for the math, and use reflection to advance the private attempt counter so we can
* assert the delay at each step without needing the real Timer.
*/
class ReconnectManagerTest extends AgentTestCase
{
	/** advance the protected $attempts counter to $n without arming a timer */
	private function setAttempts(ReconnectManager $rm, int $n): void
	{
		$p = new \ReflectionProperty(ReconnectManager::class, 'attempts');
		$p->setAccessible(true);
		$p->setValue($rm, $n);
	}

	public function testDefaultParametersAreProductionValues(): void
	{
		// 2s base, x2, 60s cap, 20% jitter (the production config)
		$rm = new ReconnectManager();
		$this->setAttempts($rm, 0);
		// with jitter the exact value varies; assert it lands in the base ±20% band
		$d = $rm->nextDelay();
		$this->assertGreaterThanOrEqual(2.0 * 0.8, $d);
		$this->assertLessThanOrEqual(2.0 * 1.2, $d);
	}

	/**
	* The ideal (pre-jitter) delay must be min(base * multiplier^N, cap). We verify
	* this by disabling jitter (jitterRatio = 0) so nextDelay() returns the exact
	* geometric value, then check the whole 2,4,8,16,32,60,60,60 progression.
	*/
	public function testExactBackoffProgressionWithoutJitter(): void
	{
		$rm = new ReconnectManager(2.0, 60.0, 2.0, 0.0); // no jitter
		$expected = [
			0 => 2.0,
			1 => 4.0,
			2 => 8.0,
			3 => 16.0,
			4 => 32.0,
			5 => 60.0, // 64 -> capped to 60
			6 => 60.0,
			7 => 60.0,
			10 => 60.0,
			20 => 60.0,
		];
		foreach ($expected as $attempt => $delay) {
			$this->setAttempts($rm, $attempt);
			$this->assertSame(
				$delay,
				$rm->nextDelay(),
				"attempt #{$attempt} expected exact delay {$delay}"
			);
		}
	}

	/**
	* min(2 * 2^N, 60) formula check at each attempt (task spec wording), no jitter.
	*/
	public function testDelayMatchesMinFormula(): void
	{
		$rm = new ReconnectManager(2.0, 60.0, 2.0, 0.0);
		for ($n = 0; $n <= 12; $n++) {
			$this->setAttempts($rm, $n);
			$formula = min(2.0 * (2 ** $n), 60.0);
			$this->assertSame(round($formula, 2), $rm->nextDelay(), "min(2*2^{$n},60)");
		}
	}

	/**
	* Jitter must stay within ±jitterRatio of the ideal delay across many samples,
	* and never below the 0.1s floor or above the cap. Statistical bounds check
	* (the impl uses mt_rand, not a mockable source, so we sample heavily).
	*/
	public function testJitterStaysWithinBoundsOverManySamples(): void
	{
		$base = 2.0;
		$ratio = 0.2;
		$rm = new ReconnectManager($base, 60.0, 2.0, $ratio);
		// pick attempts where cap is NOT yet in play so both sides of the band are live
		foreach ([0, 1, 2, 3] as $attempt) {
			$this->setAttempts($rm, $attempt);
			$ideal = min($base * (2 ** $attempt), 60.0);
			$low = $ideal * (1 - $ratio);
			$high = $ideal * (1 + $ratio);
			$sawLow = false;
			$sawHigh = false;
			for ($i = 0; $i < 3000; $i++) {
				$d = $rm->nextDelay();
				$this->assertGreaterThanOrEqual(
					round($low, 2) - 0.011,
					$d,
					"attempt #{$attempt} sample below -20% band"
				);
				$this->assertLessThanOrEqual(
					round($high, 2) + 0.011,
					$d,
					"attempt #{$attempt} sample above +20% band"
				);
				$this->assertGreaterThanOrEqual(0.1, $d, 'floor');
				if ($d < $ideal) {
					$sawLow = true;
				}
				if ($d > $ideal) {
					$sawHigh = true;
				}
			}
			// jitter genuinely varies in both directions
			$this->assertTrue($sawLow, "attempt #{$attempt}: jitter never went below ideal");
			$this->assertTrue($sawHigh, "attempt #{$attempt}: jitter never went above ideal");
		}
	}

	/**
	* At/above the cap, jitter can only pull the delay DOWN (line 116 re-clamps to
	* maxDelay), so samples must all be <= cap and the band is [cap*0.8, cap].
	*/
	public function testJitterAtCapNeverExceedsMaxDelay(): void
	{
		$rm = new ReconnectManager(2.0, 60.0, 2.0, 0.2);
		$this->setAttempts($rm, 8); // 2*256=512 -> capped
		for ($i = 0; $i < 2000; $i++) {
			$d = $rm->nextDelay();
			$this->assertLessThanOrEqual(60.0, $d, 'must never exceed cap');
			$this->assertGreaterThanOrEqual(60.0 * 0.8 - 0.011, $d, 'within -20% of cap');
		}
	}

	public function testNextDelayDoesNotMutateAttempts(): void
	{
		$rm = new ReconnectManager();
		$this->setAttempts($rm, 3);
		$rm->nextDelay();
		$rm->nextDelay();
		$this->assertSame(3, $rm->getAttempts(), 'nextDelay must be side-effect free');
	}

	public function testConfirmConnectedResetsAttemptsToZero(): void
	{
		$rm = new ReconnectManager();
		$this->setAttempts($rm, 7);
		$this->assertSame(7, $rm->getAttempts());
		$rm->confirmConnected();
		$this->assertSame(0, $rm->getAttempts(), 'backoff must reset to base after a live frame');
	}

	public function testConfirmConnectedIsNoOpWhenAlreadyZero(): void
	{
		$rm = new ReconnectManager();
		$this->assertSame(0, $rm->getAttempts());
		$rm->confirmConnected();
		$this->assertSame(0, $rm->getAttempts());
		// and it should not have emitted the "reset" log line when nothing to reset
		$this->assertStringNotContainsString('backoff reset', $this->capturedOutput());
	}

	/**
	* After a full escalate -> confirmConnected() cycle, the NEXT delay must drop
	* back to the base band, not continue escalating. This is the reset-to-base
	* behavior the live smoke test observed (0.47s after 5 attempts at fast scale).
	*/
	public function testDelayReturnsToBaseAfterConfirmConnected(): void
	{
		$rm = new ReconnectManager(2.0, 60.0, 2.0, 0.0); // no jitter for an exact assert
		$this->setAttempts($rm, 5); // escalated well up the curve (capped at 60)
		$this->assertSame(60.0, $rm->nextDelay());
		$rm->confirmConnected();
		$this->assertSame(2.0, $rm->nextDelay(), 'must restart from base delay, not keep escalating');
	}
}
