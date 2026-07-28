<?php

namespace MyAdmin\VpsHost;

use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;
use MyAdmin\VpsHost\Handlers\V1\V1HandlerInterface;

/**
* V1MessageDispatcher - explicit op => handler routing for inbound PROTOCOL_V1
* REQUEST envelopes (hub -> agent ops). The exact v1 counterpart of the legacy
* MessageDispatcher: same explicit-typed-registry architecture (step 3.3), no
* dynamic-property magic. Reply envelopes (frames carrying `re`) never reach
* this class - V1Client correlates those against its pending-request map.
*
* Registered ops (PROTOCOL_V1.md):
*   ping              §2.1  reply pong ({v,re,ok:true,data:{}})
*   cmd.exec          §2.2  spawn streamed command (reuses RunHandler core)
*   cmd.stdin         §2.2  stdin for an interactive run
*   cmd.kill          §2.2  kill a run (ADDITIONAL to legacy stop_run, which
*                            stays registered in the legacy MessageDispatcher)
*   config.maps       §2.6  hub push of the four registry map files
*   config.token      AUTH_DESIGN §3 token bootstrap/rotation push
*   agent.update      §2.8  self-update (reuses SelfUpdateHandler mechanics)
*   telemetry.sysinfo §2.5  phpsysinfo request/reply
*   pty.open          §2.3  allocate a real pty (PTYPool/PTYSession), replies {pty_id}
*   pty.data          §2.3  full-duplex raw bytes (base64), no reply
*   pty.resize        §2.3  TIOCSWINSZ via stty on the resolved pty slave, no reply
*   pty.close         §2.3  terminate + reap a pty, no reply
*
* Unknown ops are answered {ok:false,error:{code:"unknown_op"}} per §1
* ("ops documented as no reply ... unless an error occurs").
*/
class V1MessageDispatcher
{
	/**
	* @var array<string, V1HandlerInterface>
	*/
	private array $handlers = [];

	public function __construct()
	{
		$this->handlers = [
			'ping' => new Handlers\V1\PingHandler(),
			'cmd.exec' => new Handlers\V1\CmdExecHandler(),
			'cmd.stdin' => new Handlers\V1\CmdStdinHandler(),
			'cmd.kill' => new Handlers\V1\CmdKillHandler(),
			'config.maps' => new Handlers\V1\ConfigMapsHandler(),
			'config.token' => new Handlers\V1\ConfigTokenHandler(),
			'agent.update' => new Handlers\V1\AgentUpdateHandler(),
			'telemetry.sysinfo' => new Handlers\V1\TelemetrySysinfoHandler(),
			'pty.open' => new Handlers\V1\PtyOpenHandler(),
			'pty.data' => new Handlers\V1\PtyDataHandler(),
			'pty.resize' => new Handlers\V1\PtyResizeHandler(),
			'pty.close' => new Handlers\V1\PtyCloseHandler(),
		];
	}

	public function register(string $op, V1HandlerInterface $handler): void
	{
		$this->handlers[$op] = $handler;
	}

	/**
	* @return array<string, V1HandlerInterface>
	*/
	public function getHandlers(): array
	{
		return $this->handlers;
	}

	/**
	* route one inbound v1 REQUEST envelope. Decodes optional enc:"gzip" data
	* first (per-op handlers always see a plain array), answering bad_request
	* on malformed payloads instead of crashing the worker.
	*/
	public function dispatch(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		if (!V1Envelope::decodeData($envelope)) {
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'bad_request', 'malformed envelope data')));
			return;
		}
		$op = $envelope['op'];
		if (isset($this->handlers[$op])) {
			$this->handlers[$op]->handle($agent, $conn, $envelope);
		} else {
			Worker::safeEcho("[v1] Unhandled op {$op}\n");
			$conn->send(json_encode(V1Envelope::error($envelope['id'], 'unknown_op', 'no handler for op '.$op)));
		}
	}
}
