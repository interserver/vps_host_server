<?php

namespace MyAdmin\VpsHost\Handlers;

use MyAdmin\VpsHost\Agent;
use Workerman\Connection\AsyncTcpConnection;

/**
* 'run_list' - report the currently running commands back to the hub.
*/
class RunListHandler implements MessageHandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $data): void
	{
		$json = [
			'type' => 'run_list',
			'running' => $agent->running
		];
		$conn->send(json_encode($json));
	}
}
