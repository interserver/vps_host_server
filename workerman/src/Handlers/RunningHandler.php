<?php

namespace MyAdmin\VpsHost\Handlers;

use MyAdmin\VpsHost\Agent;
use Workerman\Connection\AsyncTcpConnection;

/**
* 'running' - forward interactive stdin input to a running command's process.
*/
class RunningHandler implements MessageHandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $data): void
	{
		if (isset($data['id'])) {
			$agent->running[$data['id']]['process']->stdin->write($data['stdin']);
		}
	}
}
