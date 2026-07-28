<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\V1Envelope;
use Workerman\Connection\AsyncTcpConnection;

/**
* v1 'ping' (§2.1) - pong is the reply (re set) to a ping:
* {"v":1,"re":"<id>","ok":true,"data":{}} - byte-shaped like the hub's own
* frozen pong (data forced to a JSON object, not []).
*/
class PingHandler implements V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		$reply = V1Envelope::reply($envelope['id']);
		$reply['data'] = new \stdClass(); // "data":{} not "data":[]
		$conn->send(json_encode($reply));
	}
}
