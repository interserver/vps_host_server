<?php

namespace MyAdmin\VpsHost;

use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;
use MyAdmin\VpsHost\Handlers\MessageHandlerInterface;

/**
* MessageDispatcher - explicit type => handler routing for hub messages.
*
* Replaces the old switch($data['type']) in src/Events/onMessage.php.
*/
class MessageDispatcher
{
	/**
	* @var array<string, MessageHandlerInterface>
	*/
	private array $handlers = [];

	public function __construct()
	{
		$this->handlers = [
			'login' => new Handlers\LoginHandler(),
			'timers' => new Handlers\TimersHandler(),
			'self-update' => new Handlers\SelfUpdateHandler(),
			'ping' => new Handlers\PingHandler(),
			'pong' => new Handlers\PongHandler(),
			'get_map' => new Handlers\GetMapHandler(),
			'phpsysinfo' => new Handlers\PhpsysinfoHandler(),
			'run' => new Handlers\RunHandler(),
			'run_list' => new Handlers\RunListHandler(),
			'running' => new Handlers\RunningHandler(),
			'stop_run' => new Handlers\StopRunHandler(),
		];
	}

	public function register(string $type, MessageHandlerInterface $handler): void
	{
		$this->handlers[$type] = $handler;
	}

	/**
	* @return array<string, MessageHandlerInterface>
	*/
	public function getHandlers(): array
	{
		return $this->handlers;
	}

	public function dispatch(Agent $agent, AsyncTcpConnection $conn, array $data): void
	{
		if (isset($this->handlers[$data['type']])) {
			$this->handlers[$data['type']]->handle($agent, $conn, $data);
		} else {
			Worker::safeEcho("Unhandled Mesage Type {$data['type']}\n");
		}
	}
}
