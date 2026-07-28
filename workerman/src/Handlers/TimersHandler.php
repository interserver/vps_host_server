<?php

namespace MyAdmin\VpsHost\Handlers;

use MyAdmin\VpsHost\Agent;
use Workerman\Connection\AsyncTcpConnection;

/**
* 'timers' - report the currently registered timer ids back to the hub.
*/
class TimersHandler implements MessageHandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $data): void
	{
		$json = [
			'type' => 'timers',
			'timers' => $agent->timers
		];
		$conn->send(json_encode($json));
	}
}
