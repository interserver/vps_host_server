<?php

namespace MyAdmin\VpsHost;

use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

/**
* V1Client - the agent-side PROTOCOL_V1 session: dual-running gate, the
* auth.hello/auth.welcome handshake (§2.1 + AUTH_DESIGN §3/§4), reply
* correlation (envelope id -> pending callback), and the client-originated
* (A->H) senders for config.maps pulls and queue.* ops. Inbound H->A request
* ops are routed by the V1MessageDispatcher this client owns.
*
* Dual-running gate: enabled() is true only when TokenStore holds a token.
* With no token file the agent NEVER emits or expects a v1 frame on its own -
* onConnect sends the legacy {type:"login"} and all telemetry stays
* legacy-shaped, so a token-less host is byte-identical to the pre-3.5 agent.
* (Inbound v1-shaped frames are still routed here even without a token, solely
* so the config.token bootstrap push (AUTH_DESIGN §3) can be received over the
* legacy-authenticated connection; before 3.5 such frames just logged
* "Unhandled Mesage Type" - they were never valid legacy traffic.)
*/
class V1Client
{
	private TokenStore $tokenStore;

	private V1MessageDispatcher $dispatcher;

	/**
	* @var bool true once auth.welcome has been received on the current connection
	*/
	public bool $authed = false;

	/**
	* @var string|null hub-assigned session token from auth.welcome
	*/
	public $session = null;

	/**
	* @var int|null authenticated host id echoed by auth.welcome
	*/
	public $hostId = null;

	/**
	* @var string|null bound uid from auth.welcome (e.g. "vps42")
	*/
	public $uid = null;

	/**
	* @var string|null display name from auth.welcome
	*/
	public $name = null;

	/**
	* @var array pending request-id => ['cb' => callable, 'ts' => int] awaiting replies
	*/
	private array $pending = [];

	/**
	* @var int drop pending-reply callbacks older than this many seconds
	*/
	private int $pendingTtl = 300;

	private array $config = [];

	public function __construct(?TokenStore $tokenStore = null, ?V1MessageDispatcher $dispatcher = null)
	{
		$this->tokenStore = $tokenStore !== null ? $tokenStore : new TokenStore();
		$this->dispatcher = $dispatcher !== null ? $dispatcher : new V1MessageDispatcher();
	}

	/**
	* apply the agent's merged config.ini: [v1] token_file overrides the token
	* path (test harnesses), [v1] host_id is the auth.hello host_id fallback
	* when the token file itself carries none.
	*/
	public function configure(array $config): void
	{
		$this->config = $config;
		if (!empty($config['v1']['token_file'])) {
			$this->tokenStore->setPath($config['v1']['token_file']);
		}
	}

	public function tokenStore(): TokenStore
	{
		return $this->tokenStore;
	}

	public function getDispatcher(): V1MessageDispatcher
	{
		return $this->dispatcher;
	}

	/**
	* dual-running gate: v1 mode is enabled iff a local token exists.
	*/
	public function enabled(): bool
	{
		return $this->tokenStore->hasToken();
	}

	/**
	* enabled AND welcomed on the current connection - the condition for sending
	* v1-shaped telemetry/queue traffic instead of legacy frames.
	*/
	public function isActive(): bool
	{
		return $this->authed && $this->enabled();
	}

	/**
	* fresh (re)connection: any previous session/pending correlation is void.
	*/
	public function resetSession(): void
	{
		$this->authed = false;
		$this->session = null;
		$this->uid = null;
		$this->name = null;
		$this->pending = [];
	}

	/**
	* send a v1 request envelope; when $onReply is given the reply (correlated
	* by re == id) is routed to it as ($agent, $conn, $replyEnvelope).
	*
	* @return string the envelope id
	*/
	public function send(Agent $agent, AsyncTcpConnection $conn, string $op, array $data = [], bool $gzip = false, ?callable $onReply = null): string
	{
		$envelope = V1Envelope::request($op, $data, $gzip);
		if ($onReply !== null) {
			$this->pending[$envelope['id']] = ['cb' => $onReply, 'ts' => time()];
		}
		$conn->send(json_encode($envelope));
		return $envelope['id'];
	}

	/**
	* route one inbound v1 envelope (Agent::onMessage detected it via
	* V1Envelope::isV1): replies resolve the pending map, requests go to the
	* op dispatcher.
	*/
	public function onEnvelope(Agent $agent, AsyncTcpConnection $conn, array $envelope): void
	{
		if (V1Envelope::isReply($envelope)) {
			if ($envelope['ok'] === true && !V1Envelope::decodeData($envelope)) {
				Worker::safeEcho("[v1] dropping reply {$envelope['re']} with undecodable data\n");
				unset($this->pending[$envelope['re']]);
				return;
			}
			$re = $envelope['re'];
			if (isset($this->pending[$re])) {
				$cb = $this->pending[$re]['cb'];
				unset($this->pending[$re]);
				$cb($agent, $conn, $envelope);
			} else {
				Worker::safeEcho("[v1] reply for unknown/expired request id {$re}\n");
			}
			$this->prunePending();
			return;
		}
		$this->dispatcher->dispatch($agent, $conn, $envelope);
	}

	/**
	* v1 connect handshake: auth.hello{role:"host", host_id, token, agent_version,
	* virt_type, module} as the FIRST frame (§2.1/§3 ordering rule). The welcome
	* reply flips authed and starts the timers (the v1 replacement for the legacy
	* login-ack -> LoginHandler -> setupTimers trigger); an ok:false reply is
	* logged by its AUTH_DESIGN §4 code - the hub closes the connection after
	* sending it, so recovery funnels into the normal reconnect backoff.
	*/
	public function sendHello(Agent $agent, AsyncTcpConnection $conn): void
	{
		$hostId = $this->tokenStore->getHostId();
		if ($hostId === null) {
			$hostId = (int)($this->config['v1']['host_id'] ?? 0);
		}
		$this->hostId = $hostId;
		$hello = [
			'role' => 'host',
			'host_id' => $hostId,
			'token' => (string)$this->tokenStore->getToken(),
			'agent_version' => Agent::AGENT_VERSION,
			'virt_type' => $agent->type === 'vzctl' ? 'openvz' : $agent->type,
			'module' => 'vps'
		];
		$this->send($agent, $conn, 'auth.hello', $hello, false, function (Agent $agent, AsyncTcpConnection $conn, array $reply) {
			$this->handleWelcome($agent, $conn, $reply);
		});
	}

	/**
	* auth.welcome / auth error handling (§2.1). On welcome: record
	* session/host_id/uid/name, log hub_time skew, then start the periodic
	* timers - setupTimers() for the standard set, plus hub-supplied per-timer
	* interval overrides (welcome.timers is map<name,int seconds>; names must
	* match a callable Agent method to be honored).
	*/
	public function handleWelcome(Agent $agent, AsyncTcpConnection $conn, array $reply): void
	{
		if ($reply['ok'] !== true) {
			$code = $reply['error']['code'] ?? 'unknown';
			$message = $reply['error']['message'] ?? '';
			Worker::safeEcho("[v1] auth failed: {$code} {$message} (hub will close; reconnect backoff takes over)\n");
			return;
		}
		$data = $reply['data'];
		$this->authed = true;
		$this->session = isset($data['session']) ? (string)$data['session'] : null;
		$this->hostId = isset($data['host_id']) ? (int)$data['host_id'] : $this->hostId;
		$this->uid = isset($data['uid']) ? (string)$data['uid'] : null;
		$this->name = isset($data['name']) ? (string)$data['name'] : null;
		$skew = isset($data['hub_time']) && is_int($data['hub_time']) ? $data['hub_time'] - time() : 0;
		Worker::safeEcho('[v1] auth.welcome uid='.var_export($this->uid, true).' host_id='.var_export($this->hostId, true)." clock_skew={$skew}s\n");
		// v1 replacement for the legacy login-ack -> LoginHandler -> setupTimers trigger
		$agent->setupTimers();
		if (isset($data['timers']) && is_array($data['timers'])) {
			foreach ($data['timers'] as $timerName => $interval) {
				if (is_string($timerName) && is_numeric($interval) && (int)$interval > 0 && is_callable([$agent, $timerName])) {
					$agent->addTimer($timerName, (int)$interval);
				}
			}
		}
	}

	/**
	* A->H config.maps pull (§2.6) - the v1 form of the legacy {type:"get_map"}
	* request sent by Agent::get_map_timer(). The reply payload is handed to the
	* SAME GetMapHandler file-writing logic (via ConfigMapsHandler::applyMaps)
	* that the legacy path uses, preserving the byte-compat invariant.
	*/
	public function requestMaps(Agent $agent, AsyncTcpConnection $conn): void
	{
		$this->send($agent, $conn, 'config.maps', [], false, function (Agent $agent, AsyncTcpConnection $conn, array $reply) {
			if ($reply['ok'] !== true || !is_array($reply['data'])) {
				Worker::safeEcho('[v1] config.maps pull failed: '.json_encode($reply['error'] ?? null)."\n");
				return;
			}
			(new Handlers\V1\ConfigMapsHandler())->applyMaps($agent, $conn, $reply['data']);
		});
	}

	/**
	* A->H queue.pull (§2.4) - v1 replacement for fetching get_queue over HTTP
	* vps_queue.php. Per AMENDMENT 2 the reply is a SINGLE AGGREGATE entry
	* ({history_id:0, command:"get_queue", args:{script:<raw aggregated script>}})
	* or an empty jobs array - NEVER per-row jobs. The aggregate script is
	* executed exactly like the legacy HTTP body was (backticks - see
	* Tasks/vps_queue.php), but via the local TaskWorker 'run' task so the
	* blocking execution happens in the Task worker, not the ws client worker
	* (matching where legacy queue scripts ran). queue.ack is then sent as
	* additive telemetry (new v1 capability - legacy had no ack at all; the
	* hub's optimistic <module>queueold row-flip already happened inside its
	* reused GetQueue handler at pull time).
	*/
	public function queuePull(Agent $agent, AsyncTcpConnection $conn): void
	{
		$this->send($agent, $conn, 'queue.pull', ['module' => 'vps'], false, function (Agent $agent, AsyncTcpConnection $conn, array $reply) {
			if ($reply['ok'] !== true) {
				Worker::safeEcho('[v1] queue.pull failed: '.json_encode($reply['error'] ?? null)."\n");
				return;
			}
			$jobs = $reply['data']['jobs'] ?? [];
			if (!is_array($jobs) || count($jobs) === 0) {
				return;
			}
			// AMENDMENT 2: single aggregate entry, history_id:0 sentinel
			$job = $jobs[0];
			$script = isset($job['args']['script']) && is_string($job['args']['script']) ? $job['args']['script'] : '';
			if (trim($script) === '') {
				return;
			}
			$historyId = isset($job['history_id']) && is_numeric($job['history_id']) ? (int)$job['history_id'] : 0;
			$this->runQueueScript($agent, $conn, $script, $historyId);
		});
	}

	/**
	* A->H queue.provision (§2.4) - v1 alias for the legacy get_new_vps HTTP
	* action; reply {script} is the raw provisioning script text (may be ""),
	* executed through the same TaskWorker path as queue.pull scripts. No ack
	* (provisioning scripts carry no queue_log history id).
	*/
	public function queueProvision(Agent $agent, AsyncTcpConnection $conn): void
	{
		$this->send($agent, $conn, 'queue.provision', ['module' => 'vps'], false, function (Agent $agent, AsyncTcpConnection $conn, array $reply) {
			if ($reply['ok'] !== true) {
				Worker::safeEcho('[v1] queue.provision failed: '.json_encode($reply['error'] ?? null)."\n");
				return;
			}
			$script = isset($reply['data']['script']) && is_string($reply['data']['script']) ? $reply['data']['script'] : '';
			if (trim($script) === '') {
				return;
			}
			$this->runQueueScript($agent, $conn, $script, null);
		});
	}

	/**
	* A->H queue.ack (§2.4) - fire-and-forget completion telemetry, new in v1
	* (the hub treats it as additive; the legacy optimistic row-flip is untouched).
	*/
	public function queueAck(Agent $agent, AsyncTcpConnection $conn, int $historyId, string $status, string $output): void
	{
		$this->send($agent, $conn, 'queue.ack', ['history_id' => $historyId, 'status' => $status, 'output' => $output]);
	}

	/**
	* execute a queue/provision script via the local TaskWorker 'run' task
	* (Tasks/run.php: backticks - same execution semantics the legacy
	* Tasks/vps_queue.php applied to HTTP get_queue bodies) and, when a
	* history id is known, queue.ack the outcome afterwards.
	*/
	private function runQueueScript(Agent $agent, AsyncTcpConnection $conn, string $script, ?int $historyId): void
	{
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
		$task_connection->send(json_encode(['type' => 'run', 'args' => ['cmd' => $script]]));
		$client = $this;
		$task_connection->onMessage = function ($task_connection, $task_result) use ($agent, $conn, $historyId, $client) {
			$task_connection->close();
			$output = json_decode($task_result, true);
			$output = is_string($output) ? $output : (string)$task_result;
			Worker::safeEcho('[v1] queue script finished: '.substr($output, 0, 200)."\n");
			if ($historyId !== null) {
				$client->queueAck($agent, $conn, $historyId, 'done', $output);
			}
		};
		$task_connection->connect();
	}

	/**
	* drop pending-reply callbacks the hub never answered so the map cannot
	* grow without bound on a long-lived connection.
	*/
	private function prunePending(): void
	{
		$cutoff = time() - $this->pendingTtl;
		foreach ($this->pending as $id => $entry) {
			if ($entry['ts'] < $cutoff) {
				unset($this->pending[$id]);
			}
		}
	}
}
