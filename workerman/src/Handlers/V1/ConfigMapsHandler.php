<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\Handlers\GetMapHandler;
use Workerman\Connection\AsyncTcpConnection;

/**
* v1 'config.maps' (§2.6) - hub push (or, via V1Client::requestMaps' reply
* callback, a pull reply) of the four registry map payloads
* {slices, vnc, ips, mainips}.
*
* Byte-compat invariant (E1/C6): the four files (vps.slicemap, vps.vncmap,
* vps.ipmap, vps.mainips) MUST be written byte-identically to today - so this
* handler contains NO file logic of its own; it wraps the payload back into
* the legacy {type:"get_map", content:{...}} shape and delegates to the
* UNCHANGED step-3.3 GetMapHandler (same trim/compare-before-write, same
* ebtables/xinetd-vnc rebuild triggers, same vps_get_list chain).
*/
class ConfigMapsHandler implements V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		$this->applyMaps($agent, $conn, $envelope['data']);
	}

	/**
	* shared entry point for both the push form (handle) and the pull-reply
	* form (V1Client::requestMaps).
	*/
	public function applyMaps(Agent $agent, AsyncTcpConnection $conn, array $maps): void
	{
		(new GetMapHandler())->handle($agent, $conn, ['type' => 'get_map', 'content' => $maps]);
	}
}
