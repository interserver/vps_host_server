<?php

namespace MyAdmin\VpsHost\Handlers\V1;

use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\Handlers\SelfUpdateHandler;
use Workerman\Connection\AsyncTcpConnection;

/**
* v1 'agent.update' (§2.8) - {url?, restart (default true), jitter_max?
* (default 60)}. Extends the legacy SelfUpdateHandler so the actual update
* mechanics (runUpdate: bundled update.sh or url-override download, optional
* start.php reload) stay single-sourced; only the field handling is v1:
* one explicit rand(1, jitter_max) splay replaces the legacy hardcoded
* double rand(1,30), and restart/url come from the envelope.
*/
class AgentUpdateHandler extends SelfUpdateHandler implements V1HandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		$d = $envelope['data'];
		$jitterMax = isset($d['jitter_max']) && is_numeric($d['jitter_max']) ? (int)$d['jitter_max'] : 60;
		if ($jitterMax > 0) {
			sleep(rand(1, $jitterMax));
		}
		$url = isset($d['url']) && is_string($d['url']) && $d['url'] !== '' ? $d['url'] : null;
		$this->runUpdate($url, (bool)($d['restart'] ?? true));
	}
}
