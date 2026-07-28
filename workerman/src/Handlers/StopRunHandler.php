<?php

namespace MyAdmin\VpsHost\Handlers;

use MyAdmin\VpsHost\Agent;
use Workerman\Connection\AsyncTcpConnection;

/**
* 'stop_run' - close a running command's pipes and SIGKILL the process.
*/
class StopRunHandler implements MessageHandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $data): void
	{
		if (isset($data['id'])) {
			$agent->running[$data['id']]['process']->stdin->close();
			$agent->running[$data['id']]['process']->stdout->close();
			$agent->running[$data['id']]['process']->stderr->close();
			$agent->running[$data['id']]['process']->terminate(SIGKILL);
		}
	}
}
