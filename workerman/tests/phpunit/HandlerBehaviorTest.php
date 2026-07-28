<?php

namespace MyAdmin\VpsHost\Tests;

use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\MessageDispatcher;
use MyAdmin\VpsHost\Handlers\MessageHandlerInterface;
use MyAdmin\VpsHost\Handlers\LoginHandler;
use MyAdmin\VpsHost\Handlers\TimersHandler;
use MyAdmin\VpsHost\Handlers\PingHandler;
use MyAdmin\VpsHost\Handlers\PongHandler;
use MyAdmin\VpsHost\Handlers\RunListHandler;
use MyAdmin\VpsHost\Tests\Fakes\FakeConnection;
use MyAdmin\VpsHost\Tests\Fakes\FakeGlobalData;
use Workerman\Connection\AsyncTcpConnection;

/**
* Behavioral tests for the handlers that are unit-testable without a live event
* loop / real subprocess / real hub. Each asserts output/side-effects match the
* old src/Events/onMessage.php switch behavior.
*/
class HandlerBehaviorTest extends AgentTestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		$GLOBALS['global'] = new FakeGlobalData();
	}

	private function agent(): Agent
	{
		$a = new Agent();
		$a->conn = new FakeConnection();
		return $a;
	}

	public function testAllElevenHandlersImplementInterfaceAndConstruct(): void
	{
		foreach ((new MessageDispatcher())->getHandlers() as $type => $handler) {
			$this->assertInstanceOf(MessageHandlerInterface::class, $handler, $type);
			$rm = new \ReflectionMethod($handler, 'handle');
			$this->assertSame('handle', $rm->getName());
			$this->assertSame(3, $rm->getNumberOfParameters(), "{$type}::handle arity");
		}
	}

	public function testPingHandlerSendsPong(): void
	{
		$agent = $this->agent();
		$conn = new FakeConnection();
		$agent->conn = $conn;
		(new PingHandler())->handle($agent, $conn, ['type' => 'ping']);
		$this->assertSame(['type' => 'pong'], $conn->lastDecoded());
	}

	public function testPongHandlerIsNoOp(): void
	{
		$agent = $this->agent();
		$conn = new FakeConnection();
		(new PongHandler())->handle($agent, $conn, ['type' => 'pong']);
		$this->assertSame([], $conn->sent, 'pong must not send anything');
	}

	public function testLoginHandlerHostTriggersSetupTimers(): void
	{
		// spy Agent: override setupTimers to record it was called instead of
		// touching real timers / task connections.
		$agent = new class extends Agent {
			public bool $setupCalled = false;
			public function setupTimers()
			{
				$this->setupCalled = true;
			}
		};
		$conn = new FakeConnection();
		$agent->conn = $conn;
		(new LoginHandler())->handle($agent, $conn, ['type' => 'login', 'ima' => 'host']);
		$this->assertTrue($agent->setupCalled);
	}

	public function testLoginHandlerNonHostDoesNotSetupTimers(): void
	{
		$agent = new class extends Agent {
			public bool $setupCalled = false;
			public function setupTimers()
			{
				$this->setupCalled = true;
			}
		};
		$conn = new FakeConnection();
		(new LoginHandler())->handle($agent, $conn, ['type' => 'login', 'ima' => 'client']);
		$this->assertFalse($agent->setupCalled);
	}

	public function testTimersHandlerReportsTimerMap(): void
	{
		$agent = $this->agent();
		$agent->timers = ['vps_get_list' => 7, 'check_interval' => 9];
		$conn = new FakeConnection();
		(new TimersHandler())->handle($agent, $conn, ['type' => 'timers']);
		$decoded = $conn->lastDecoded();
		$this->assertSame('timers', $decoded['type']);
		$this->assertSame(['vps_get_list' => 7, 'check_interval' => 9], $decoded['timers']);
	}

	public function testRunListHandlerReportsRunningMap(): void
	{
		$agent = $this->agent();
		// realistic-ish running map (process objects omitted; RunList just serializes the map)
		$agent->running = ['abc123' => ['command' => 'echo hi', 'id' => 'abc123', 'for' => 'test', 'process' => null]];
		$conn = new FakeConnection();
		(new RunListHandler())->handle($agent, $conn, ['type' => 'run_list']);
		$decoded = $conn->lastDecoded();
		$this->assertSame('run_list', $decoded['type']);
		$this->assertArrayHasKey('abc123', $decoded['running']);
		$this->assertSame('echo hi', $decoded['running']['abc123']['command']);
	}
}
