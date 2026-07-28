<?php

namespace MyAdmin\VpsHost\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\V1Envelope;
use MyAdmin\VpsHost\V1MessageDispatcher;
use MyAdmin\VpsHost\Handlers\V1\V1HandlerInterface;
use MyAdmin\VpsHost\Tests\Fakes\FakeConnection;
use MyAdmin\VpsHost\Tests\Fakes\FakeGlobalData;
use Workerman\Connection\AsyncTcpConnection;

/**
* Unit coverage for V1MessageDispatcher - the v1 op => handler registry. Mirrors
* MessageDispatcherTest for the legacy dispatcher: confirms all 12 v1 ops map to
* the correct handler class, routing delivers the intact envelope to exactly the
* right handler, unknown ops return an unknown_op error, and the gzip-guard
* turns a malformed enc:"gzip" payload into a graceful bad_request rather than a
* fatal.
*
* Step 3.6 added the 4 pty.* ops (PROTOCOL_V1 §2.3) on top of the 8 registered
* as of step 3.5 - EXPECTED/count updated accordingly.
*/
class V1MessageDispatcherTest extends AgentTestCase
{
	/** @var array<string, string> expected op => handler FQCN */
	private const EXPECTED = [
		'ping' => \MyAdmin\VpsHost\Handlers\V1\PingHandler::class,
		'cmd.exec' => \MyAdmin\VpsHost\Handlers\V1\CmdExecHandler::class,
		'cmd.stdin' => \MyAdmin\VpsHost\Handlers\V1\CmdStdinHandler::class,
		'cmd.kill' => \MyAdmin\VpsHost\Handlers\V1\CmdKillHandler::class,
		'config.maps' => \MyAdmin\VpsHost\Handlers\V1\ConfigMapsHandler::class,
		'config.token' => \MyAdmin\VpsHost\Handlers\V1\ConfigTokenHandler::class,
		'agent.update' => \MyAdmin\VpsHost\Handlers\V1\AgentUpdateHandler::class,
		'telemetry.sysinfo' => \MyAdmin\VpsHost\Handlers\V1\TelemetrySysinfoHandler::class,
		'pty.open' => \MyAdmin\VpsHost\Handlers\V1\PtyOpenHandler::class,
		'pty.data' => \MyAdmin\VpsHost\Handlers\V1\PtyDataHandler::class,
		'pty.resize' => \MyAdmin\VpsHost\Handlers\V1\PtyResizeHandler::class,
		'pty.close' => \MyAdmin\VpsHost\Handlers\V1\PtyCloseHandler::class,
	];

	public function testRegistryHasExactlyTwelveOps(): void
	{
		$handlers = (new V1MessageDispatcher())->getHandlers();
		$this->assertCount(12, $handlers);
		$this->assertSame(array_keys(self::EXPECTED), array_keys($handlers));
	}

	public function testEachOpMapsToCorrectHandlerClass(): void
	{
		$handlers = (new V1MessageDispatcher())->getHandlers();
		foreach (self::EXPECTED as $op => $class) {
			$this->assertArrayHasKey($op, $handlers, "missing handler for op '{$op}'");
			$this->assertInstanceOf($class, $handlers[$op], "wrong class for op '{$op}'");
			$this->assertInstanceOf(V1HandlerInterface::class, $handlers[$op]);
		}
	}

	public function testRegisterAddsOrOverridesHandler(): void
	{
		$dispatcher = new V1MessageDispatcher();
		$custom = $this->makeSpy();
		$dispatcher->register('ping', $custom);
		$this->assertSame($custom, $dispatcher->getHandlers()['ping']);
	}

	/** every op routes to its own slot and no other */
	#[DataProvider('opProvider')]
	public function testEachOpRoutesToItsHandler(string $op): void
	{
		$GLOBALS['global'] = new FakeGlobalData();
		$dispatcher = new V1MessageDispatcher();

		$spies = [];
		foreach (array_keys($dispatcher->getHandlers()) as $o) {
			$spy = $this->makeSpy();
			$dispatcher->register($o, $spy);
			$spies[$o] = $spy;
		}

		$agent = new Agent();
		$conn = new FakeConnection();
		$envelope = V1Envelope::request($op, ['probe' => 1]);
		$dispatcher->dispatch($agent, $conn, $envelope);

		$this->assertSame(1, $spies[$op]->calls, "'{$op}' handler was not invoked exactly once");
		$this->assertSame($envelope['id'], $spies[$op]->envelope['id'] ?? null, "'{$op}' got a mangled envelope");
		$this->assertSame(['probe' => 1], $spies[$op]->envelope['data'] ?? null);

		foreach ($spies as $o => $spy) {
			if ($o !== $op) {
				$this->assertSame(0, $spy->calls, "unexpected dispatch to '{$o}' for a '{$op}' envelope");
			}
		}
	}

	public static function opProvider(): array
	{
		return array_map(fn ($op) => [$op], array_keys(self::EXPECTED));
	}

	public function testUnknownOpReturnsUnknownOpError(): void
	{
		$GLOBALS['global'] = new FakeGlobalData();
		$dispatcher = new V1MessageDispatcher();
		$agent = new Agent();
		$conn = new FakeConnection();

		$envelope = V1Envelope::request('no.such.op', []);
		$dispatcher->dispatch($agent, $conn, $envelope);

		$this->assertCount(1, $conn->sent, 'unknown op must produce exactly one reply');
		$reply = $conn->lastDecoded();
		$this->assertFalse($reply['ok']);
		$this->assertSame($envelope['id'], $reply['re']);
		$this->assertSame('unknown_op', $reply['error']['code']);
		$this->assertStringContainsString('[v1] Unhandled op no.such.op', $this->capturedOutput());
	}

	public function testMalformedGzipEnvelopeReturnsBadRequestNotFatal(): void
	{
		$GLOBALS['global'] = new FakeGlobalData();
		$dispatcher = new V1MessageDispatcher();
		$agent = new Agent();
		$conn = new FakeConnection();

		// a config.maps envelope claiming gzip but with a non-decodable payload
		$envelope = [
			'v' => 1, 'id' => 'req-bad', 'op' => 'config.maps', 'ts' => time(),
			'enc' => 'gzip', 'data' => '@@@not-base64@@@'
		];
		// must NOT throw / fatal
		$dispatcher->dispatch($agent, $conn, $envelope);

		$this->assertCount(1, $conn->sent, 'malformed gzip must produce a graceful error reply');
		$reply = $conn->lastDecoded();
		$this->assertFalse($reply['ok']);
		$this->assertSame('req-bad', $reply['re']);
		$this->assertSame('bad_request', $reply['error']['code']);
	}

	public function testMalformedGzipDoesNotReachHandler(): void
	{
		$GLOBALS['global'] = new FakeGlobalData();
		$dispatcher = new V1MessageDispatcher();
		$spy = $this->makeSpy();
		$dispatcher->register('config.maps', $spy);

		$agent = new Agent();
		$conn = new FakeConnection();
		$envelope = [
			'v' => 1, 'id' => 'req-bad2', 'op' => 'config.maps', 'ts' => time(),
			'enc' => 'gzip', 'data' => base64_encode('not a zlib stream')
		];
		$dispatcher->dispatch($agent, $conn, $envelope);

		$this->assertSame(0, $spy->calls, 'handler must not be reached on a malformed gzip payload');
		$this->assertSame('bad_request', $conn->lastDecoded()['error']['code']);
	}

	/** an anonymous recording spy handler */
	private function makeSpy(): V1HandlerInterface
	{
		return new class implements V1HandlerInterface {
			public int $calls = 0;
			public array $envelope = [];
			public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
			{
				$this->calls++;
				$this->envelope = $envelope;
			}
		};
	}
}
