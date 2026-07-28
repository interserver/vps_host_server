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
* Drive a realistic fake frame for every one of the 11 message types through
* MessageDispatcher::dispatch() and confirm the correct handler slot is invoked
* with the frame intact. Real handlers are swapped for recording spies so the
* side-effecting ones (self-update exec, get_map file writes, run subprocess,
* phpsysinfo task dispatch) do not actually fire in the test environment.
*/
class DispatchRoutingTest extends AgentTestCase
{
	/** representative fake frames matching the old onMessage.php switch cases */
	public static function frameProvider(): array
	{
		return [
			'login' => ['login', ['type' => 'login', 'ima' => 'host', 'name' => 'host1', 'module' => 'vps']],
			'timers' => ['timers', ['type' => 'timers']],
			'self-update' => ['self-update', ['type' => 'self-update']],
			'ping' => ['ping', ['type' => 'ping']],
			'pong' => ['pong', ['type' => 'pong']],
			'get_map' => ['get_map', ['type' => 'get_map', 'content' => ['mainips' => '1.2.3.4', 'ips' => '', 'slices' => '', 'vnc' => '']]],
			'phpsysinfo' => ['phpsysinfo', ['type' => 'phpsysinfo', 'params' => ['plugin' => 'complete']]],
			'run' => ['run', ['type' => 'run', 'id' => 'r1', 'command' => 'echo hello', 'for' => 'tester', 'update_after' => false]],
			'run_list' => ['run_list', ['type' => 'run_list']],
			'running' => ['running', ['type' => 'running', 'id' => 'r1', 'stdin' => "yes\n"]],
			'stop_run' => ['stop_run', ['type' => 'stop_run', 'id' => 'r1']],
		];
	}

	#[DataProvider('frameProvider')]
	public function testEachTypeRoutesToItsHandler(string $type, array $frame): void
	{
		$GLOBALS['global'] = new FakeGlobalData();

		$dispatcher = new MessageDispatcher();
		// swap ALL 11 real handlers for spies of the same key
		$spies = [];
		foreach (array_keys($dispatcher->getHandlers()) as $t) {
			$spy = new class implements MessageHandlerInterface {
				public int $calls = 0;
				public array $data = [];
				public function handle(Agent $agent, AsyncTcpConnection $conn, array $data): void
				{
					$this->calls++;
					$this->data = $data;
				}
			};
			$dispatcher->register($t, $spy);
			$spies[$t] = $spy;
		}

		$agent = new Agent($dispatcher);
		$conn = new FakeConnection();
		$agent->conn = $conn;

		// entry point is the real Agent::onMessage (json-encode to exercise decode+guard too)
		$agent->onMessage($conn, json_encode($frame));

		$this->assertSame(1, $spies[$type]->calls, "'{$type}' handler was not invoked exactly once");
		$this->assertSame($frame, $spies[$type]->data, "'{$type}' handler received a mangled frame");

		// no OTHER handler fired
		foreach ($spies as $t => $spy) {
			if ($t !== $type) {
				$this->assertSame(0, $spy->calls, "unexpected dispatch to '{$t}' for a '{$type}' frame");
			}
		}
	}
}
