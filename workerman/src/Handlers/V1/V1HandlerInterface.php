<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use Workerman\Connection\AsyncTcpConnection;

/**
* Contract for PROTOCOL_V1 op handlers (the v1 counterpart of
* Handlers\MessageHandlerInterface). $envelope is the FULL decoded v1 request
* envelope {v,id,op,ts,data} - V1MessageDispatcher has already run
* V1Envelope::decodeData() on it, so $envelope['data'] is always a plain array.
*/
interface V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void;
}
