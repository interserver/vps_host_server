<?php

namespace MyAdmin\VpsHost\Handlers;

use MyAdmin\VpsHost\Agent;
use Workerman\Connection\AsyncTcpConnection;

/**
* 'phpsysinfo' - run phpsysinfo.php via the local TaskWorker and reply with the
* gzcompressed/base64 encoded result.
*
* The TaskWorker round-trip lives in runSysinfo() so the v1 telemetry.sysinfo
* handler (Handlers\V1\TelemetrySysinfoHandler) can reuse it; this legacy
* handle() reply frame is behavior-identical to before.
*/
class PhpsysinfoHandler implements MessageHandlerInterface
{
	public function handle(Agent $agent, AsyncTcpConnection $conn, array $data): void
	{
		$orig_params = $data['params'];
		$conn = $agent->conn;
		$this->runSysinfo($agent, $data['params'], function ($task_result) use ($conn, $data, $orig_params) {
			$data['params'] = $orig_params;
			$data['data'] = base64_encode(gzcompress(json_encode($task_result), 9));
			$conn->send(json_encode($data));
		});
	}

	/**
	* run workerman/phpsysinfo.php with the given request params (json flag
	* forced on, exactly as before) via the local TaskWorker 'run' task and
	* hand the decoded result to $onResult.
	*/
	protected function runSysinfo(Agent $agent, array $params, callable $onResult): void
	{
		/**
		* @var \GlobalData\Client
		*/
		global $global;
		$dir = __DIR__.'/../../../';
		$params['json'] = '';
		$args = escapeshellarg(json_encode($params));
		$cmd = 'php '.$dir.'workerman/phpsysinfo.php '.$args;
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
		$task_connection->send(json_encode(['type' => 'run', 'args' => ['cmd' => $cmd]]));
		$task_connection->onMessage = function ($task_connection, $task_result) use ($onResult) {
			$task_result = json_decode($task_result);
			if (!is_array($task_result)) {
				$task_result = json_decode($task_result);
			}
			//var_dump($task_result);
			$task_connection->close();
			$onResult($task_result);
		};
		$task_connection->connect();
	}
}
