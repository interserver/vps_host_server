<?php

namespace MyAdmin\VpsHost\Handlers;

use MyAdmin\VpsHost\Agent;
use Workerman\Connection\AsyncTcpConnection;

/**
* 'login' - hub acknowledged our login; if we are a host, start the periodic timers.
*/
class LoginHandler implements MessageHandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $data): void
	{
		if ($data['ima'] == 'host') {
			$agent->setupTimers();
		}
	}
}
