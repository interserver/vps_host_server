<?php

namespace MyAdmin\VpsHost\Handlers;

use MyAdmin\VpsHost\Agent;
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

/**
* 'self-update' - run update.sh (after a random splay) then reload the daemon.
*
* The update/reload mechanics live in runUpdate() so the v1 agent.update
* handler (Handlers\V1\AgentUpdateHandler) can reuse them with its explicit
* url/restart/jitter_max fields; this legacy handle() is behavior-identical
* to before (two rand(1,30) sleeps, bundled update.sh, always reload).
*/
class SelfUpdateHandler implements MessageHandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $data): void
	{
		sleep(rand(1, 30));
		sleep(rand(1, 30));
		$this->runUpdate(null, true);
	}

	/**
	* the shared update mechanics: $url null = legacy behavior (exec the
	* bundled update.sh contents); a url = v1 override (download the script
	* to a temp file and run it). $restart reloads the daemon afterwards
	* (legacy always did).
	*/
	protected function runUpdate(?string $url, bool $restart): void
	{
		if ($url !== null && $url !== '') {
			$tmp = tempnam(sys_get_temp_dir(), 'agentupdate');
			Worker::safeEcho(exec('curl -fsSL '.escapeshellarg($url).' -o '.escapeshellarg($tmp).' && bash '.escapeshellarg($tmp).'; rm -f '.escapeshellarg($tmp)).PHP_EOL);
		} else {
			Worker::safeEcho(exec(file_get_contents(__DIR__.'/../../update.sh')).PHP_EOL);
		}
		if ($restart) {
			Worker::safeEcho(exec('php '.__DIR__.'/../../start.php reload').PHP_EOL);
		}
	}
}
