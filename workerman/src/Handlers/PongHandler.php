<?php

namespace MyAdmin\VpsHost\Handlers;

use MyAdmin\VpsHost\Agent;
use Workerman\Connection\AsyncTcpConnection;

/**
* 'pong' - no-op (lastMessageTime is already refreshed in Agent::onMessage).
*/
class PongHandler implements MessageHandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $data): void
	{
	}
}
