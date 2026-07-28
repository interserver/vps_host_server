<?php

namespace MyAdmin\VpsHost\Tests;

use MyAdmin\VpsHost\MessageDispatcher;
use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\Handlers\MessageHandlerInterface;
use MyAdmin\VpsHost\Tests\Fakes\FakeConnection;
use MyAdmin\VpsHost\Tests\Fakes\FakeGlobalData;

/**
* Registry correctness: the 11 hub message types map to the right handler classes,
* every handler implements the interface, and unknown types fall through cleanly.
*/
class MessageDispatcherTest extends AgentTestCase
{
	/** @var array<string, string> expected type => handler FQCN */
	private const EXPECTED = [
		'login' => \MyAdmin\VpsHost\Handlers\LoginHandler::class,
		'timers' => \MyAdmin\VpsHost\Handlers\TimersHandler::class,
		'self-update' => \MyAdmin\VpsHost\Handlers\SelfUpdateHandler::class,
		'ping' => \MyAdmin\VpsHost\Handlers\PingHandler::class,
		'pong' => \MyAdmin\VpsHost\Handlers\PongHandler::class,
		'get_map' => \MyAdmin\VpsHost\Handlers\GetMapHandler::class,
		'phpsysinfo' => \MyAdmin\VpsHost\Handlers\PhpsysinfoHandler::class,
		'run' => \MyAdmin\VpsHost\Handlers\RunHandler::class,
		'run_list' => \MyAdmin\VpsHost\Handlers\RunListHandler::class,
		'running' => \MyAdmin\VpsHost\Handlers\RunningHandler::class,
		'stop_run' => \MyAdmin\VpsHost\Handlers\StopRunHandler::class,
	];

	public function testRegistryHasExactlyElevenTypes(): void
	{
		$handlers = (new MessageDispatcher())->getHandlers();
		$this->assertCount(11, $handlers);
		$this->assertSame(array_keys(self::EXPECTED), array_keys($handlers));
	}

	public function testEachTypeMapsToCorrectHandlerClass(): void
	{
		$handlers = (new MessageDispatcher())->getHandlers();
		foreach (self::EXPECTED as $type => $class) {
			$this->assertArrayHasKey($type, $handlers, "missing handler for '{$type}'");
			$this->assertInstanceOf($class, $handlers[$type], "wrong class for '{$type}'");
			$this->assertInstanceOf(MessageHandlerInterface::class, $handlers[$type]);
		}
	}

	public function testRegisterAddsOrOverridesHandler(): void
	{
		$dispatcher = new MessageDispatcher();
		$custom = new class implements MessageHandlerInterface {
			public bool $called = false;
			public function handle(Agent $agent, \Workerman\Connection\AsyncTcpConnection $conn, array $data): void
			{
				$this->called = true;
			}
		};
		$dispatcher->register('login', $custom);
		$this->assertSame($custom, $dispatcher->getHandlers()['login']);
	}

	public function testUnknownTypeDoesNotThrow(): void
	{
		$GLOBALS['global'] = new FakeGlobalData();
		$dispatcher = new MessageDispatcher();
		$agent = new Agent($dispatcher);
		$conn = new FakeConnection();
		// no exception, and nothing is sent on the connection
		$dispatcher->dispatch($agent, $conn, ['type' => 'no_such_type']);
		$this->assertSame([], $conn->sent);
	}
}
