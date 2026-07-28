<?php

namespace MyAdmin\VpsHost\Handlers;

use MyAdmin\VpsHost\Agent;
use Workerman\Connection\AsyncTcpConnection;

/**
* Contract for one hub-message-type handler ($data is the full json-decoded frame).
*/
interface MessageHandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $data): void;
}
