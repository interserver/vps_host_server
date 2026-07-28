<?php

namespace MyAdmin\VpsHost\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\MessageDispatcher;
use MyAdmin\VpsHost\Handlers\MessageHandlerInterface;
use MyAdmin\VpsHost\Tests\Fakes\FakeConnection;
use MyAdmin\VpsHost\Tests\Fakes\FakeGlobalData;
use Workerman\Connection\AsyncTcpConnection;

/**
* The step-3.3 robustness fix in Agent::onMessage(): a malformed wire frame must be
* logged with the exact original "Unhandled Mesage Type \n" string and return
* gracefully (no TypeError / process death), while a valid frame still dispatches.
*/
class MalformedJsonGuardTest extends AgentTestCase
{
	private function makeAgentWithSpy(?object &$spy): Agent
	{
		$dispatcher = new MessageDispatcher();
		$spy = new class implements MessageHandlerInterface {
			public int $calls = 0;
			public array $lastData = [];
			public function handle(Agent $agent, AsyncTcpConnection $conn, array $data): void
			{
				$this->calls++;
				$this->lastData = $data;
			}
		};
		// replace the ping handler with a spy so we can observe a valid dispatch
		$dispatcher->register('ping', $spy);
		return new Agent($dispatcher);
	}

	/**
	* Drive Agent::onMessage() and capture Worker::safeEcho output.
	*/
	private function driveOnMessage(Agent $agent, FakeConnection $conn, string $raw): string
	{
		$agent->onMessage($conn, $raw);
		return $this->capturedOutput();
	}

	public static function malformedProvider(): array
	{
		return [
			'invalid json string' => ['this is not json'],
			'json null' => ['null'],
			'json scalar int' => ['12345'],
			'json scalar string' => ['"just a string"'],
			'object missing type key' => ['{"foo":"bar"}'],
			'object type is null' => ['{"type":null}'],
		];
	}

	#[DataProvider('malformedProvider')]
	public function testMalformedFrameHandledGracefully(string $raw): void
	{
		$GLOBALS['global'] = new FakeGlobalData();
		$agent = $this->makeAgentWithSpy($spy);
		$conn = new FakeConnection();

		$out = $this->driveOnMessage($agent, $conn, $raw);

		// exact original string, character for character (note the misspelling + trailing space + \n)
		$this->assertStringContainsString("Unhandled Mesage Type \n", $out);
		// nothing dispatched, nothing sent, spy never invoked
		$this->assertSame(0, $spy->calls);
		$this->assertSame([], $conn->sent);
	}

	public function testValidFrameStillDispatches(): void
	{
		$GLOBALS['global'] = new FakeGlobalData();
		$agent = $this->makeAgentWithSpy($spy);
		$conn = new FakeConnection();

		$this->driveOnMessage($agent, $conn, '{"type":"ping"}');

		$this->assertSame(1, $spy->calls, 'valid ping frame should reach the handler');
		$this->assertSame('ping', $spy->lastData['type']);
	}

	public function testValidPingThroughRealHandlerSendsPong(): void
	{
		// end-to-end through the REAL PingHandler (no spy): {"type":"ping"} -> pong frame
		$GLOBALS['global'] = new FakeGlobalData();
		$agent = new Agent();
		$conn = new FakeConnection();
		$agent->conn = $conn;

		$this->driveOnMessage($agent, $conn, '{"type":"ping"}');

		$this->assertNotEmpty($conn->sent);
		$this->assertSame(['type' => 'pong'], $conn->lastDecoded());
	}

	public function testMalformedFrameDoesNotThrow(): void
	{
		$GLOBALS['global'] = new FakeGlobalData();
		$agent = $this->makeAgentWithSpy($spy);
		$conn = new FakeConnection();
		// the whole point of the fix: no TypeError bubbles out
		$this->expectNotToPerformAssertions();
		$this->driveOnMessage($agent, $conn, 'garbage{');
	}
}
