<?php

namespace MyAdmin\VpsHost\Tests;

use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\ReconnectManager;
use MyAdmin\VpsHost\MessageDispatcher;
use MyAdmin\VpsHost\Tests\Fakes\FakeConnection;
use MyAdmin\VpsHost\Tests\Fakes\FakeGlobalData;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\ConnectionInterface;

/**
* Step 3.4: Agent wiring. The old onClose/checkHeartbeat path called
* Worker::stopAll() to kill the process; the rebuild must instead funnel every
* connection-loss into ReconnectManager::scheduleReconnect(). We inject a spy
* ReconnectManager to observe the calls without a live Workerman event loop.
*/
class AgentReconnectWiringTest extends AgentTestCase
{
	private function spyManager(): ReconnectManager
	{
		return new class extends ReconnectManager {
			public int $scheduleCalls = 0;
			public int $confirmCalls = 0;
			public array $reasons = [];
			/** @var callable[] captured attempt callables */
			public array $capturedAttempts = [];
			public function scheduleReconnect(callable $attempt, string $reason = ''): void
			{
				$this->scheduleCalls++;
				$this->reasons[] = $reason;
				$this->capturedAttempts[] = $attempt;
			}
			public function confirmConnected(): void
			{
				$this->confirmCalls++;
			}
		};
	}

	protected function setUp(): void
	{
		parent::setUp();
		$GLOBALS['global'] = new FakeGlobalData();
	}

	public function testOnCloseSchedulesReconnect(): void
	{
		$spy = $this->spyManager();
		$agent = new Agent(new MessageDispatcher(), $spy);
		$conn = new FakeConnection();

		$agent->onClose($conn);

		$this->assertSame(1, $spy->scheduleCalls, 'onClose must schedule exactly one reconnect');
		$this->assertSame('connection closed', $spy->reasons[0]);
	}

	public function testOnCloseDoesNotStopWorker(): void
	{
		// The whole point of step 3.4: onClose must NOT kill the process. If it called
		// Worker::stopAll() the run status would flip; we assert the schedule path ran
		// instead and no fatal/stop happened (test simply completing proves no stopAll
		// exit, and the spy proves the reconnect path was taken).
		$spy = $this->spyManager();
		$agent = new Agent(new MessageDispatcher(), $spy);
		$conn = new FakeConnection();
		$agent->onClose($conn);
		$this->assertGreaterThan(0, $spy->scheduleCalls);
		$out = $this->capturedOutput();
		$this->assertStringContainsString('scheduling reconnect', $out);
	}

	public function testOnErrorSchedulesReconnectForConnectFail(): void
	{
		$spy = $this->spyManager();
		$agent = new Agent(new MessageDispatcher(), $spy);
		$conn = new FakeConnection();

		$agent->onError($conn, ConnectionInterface::CONNECT_FAIL, 'connect refused');

		$this->assertSame(1, $spy->scheduleCalls, 'CONNECT_FAIL must schedule a reconnect (defense in depth)');
		$this->assertStringContainsString('connect failed', $spy->reasons[0]);
	}

	public function testOnErrorIgnoresNonConnectFailCodes(): void
	{
		$spy = $this->spyManager();
		$agent = new Agent(new MessageDispatcher(), $spy);
		$conn = new FakeConnection();

		// SEND_FAIL (an error on an established connection) - onClose handles recovery
		// for those via destroy(), so onError must NOT itself schedule here.
		$agent->onError($conn, ConnectionInterface::SEND_FAIL, 'send fail on live conn');

		$this->assertSame(0, $spy->scheduleCalls, 'only CONNECT_FAIL should schedule from onError');
	}

	/**
	* The Agent resets the backoff by calling confirmConnected() from onMessage (first
	* application frame), NOT from onConnect. Verify onMessage triggers it and onConnect
	* does not.
	*/
	public function testOnMessageConfirmsConnectedButOnConnectDoesNot(): void
	{
		$spy = $this->spyManager();
		$agent = new Agent(new MessageDispatcher(), $spy);
		$agent->hostname = 'test-host';
		$conn = new FakeConnection();

		$agent->onConnect($conn);
		$this->assertSame(0, $spy->confirmCalls, 'onConnect must NOT reset backoff (accept-then-die guard)');

		// a valid application frame
		$agent->onMessage($conn, json_encode(['type' => 'pong']));
		$this->assertSame(1, $spy->confirmCalls, 'first app frame in onMessage resets the backoff');
	}

	/**
	* End-to-end at the manager level: the attempt callable captured from onClose,
	* when invoked, must re-wire the connection callbacks (which destroy() nulled)
	* before reconnecting. We use a real ReconnectManager attempt closure shape by
	* invoking Agent::reconnectToHub through the captured callable against a fake conn
	* and asserting the callbacks are (re)attached.
	*/
	public function testCapturedAttemptRewiresCallbacks(): void
	{
		$spy = $this->spyManager();
		$agent = new Agent(new MessageDispatcher(), $spy);
		$conn = new FakeConnection();

		$agent->onClose($conn);
		$this->assertNotEmpty($spy->capturedAttempts, 'onClose should have captured an attempt callable');

		// simulate destroy() having nulled the callbacks
		$conn->onMessage = null;
		$conn->onClose = null;
		$conn->onError = null;

		// invoke the captured attempt; reconnectToHub calls wireConnection then reconnect(0).
		// FakeConnection::reconnect is inherited but harmless (no socket); wireConnection
		// sets the callbacks back. We only assert the re-wire happened.
		try {
			($spy->capturedAttempts[0])();
		} catch (\Throwable $e) {
			// reconnect(0) may try to touch a real event loop; ignore - we only care
			// that wireConnection ran first and reattached the callbacks.
		}

		$this->assertIsCallable($conn->onMessage, 'onMessage must be re-wired before reconnect');
		$this->assertIsCallable($conn->onClose, 'onClose must be re-wired before reconnect');
		$this->assertIsCallable($conn->onError, 'onError must be re-wired before reconnect');
	}
}
