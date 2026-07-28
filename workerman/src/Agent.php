<?php

namespace MyAdmin\VpsHost;

use Workerman\Worker;
use Workerman\Timer;
use Workerman\Connection\AsyncTcpConnection;

/**
* Agent - the live state + lifecycle callbacks of the VpsHostWorker websocket client.
*
* Replaces the old stdObject dynamic-property/closure dispatch (src/Events/*.php).
* Every method here is a 1:1 port of the closure file of the same name; message-type
* handling (the old onMessage.php switch) now lives in MessageDispatcher + Handlers/*.
*/
class Agent
{
	/**
	* agent build string sent in auth.hello (PROTOCOL_V1 §2.1)
	*/
	public const AGENT_VERSION = '3.5.0';

	public $debug = true;
	public $conn = null;
	public $var = null;
	public $vps_list = [];
	public $bandwidth = null;
	public $traffic_last = null;
	public $timers = [];
	public $ipmap = [];
	public $running = [];
	public $type = 'kvm';
	public $hostname = '';
	public $config = [];

	/**
	* @var MessageDispatcher
	*/
	public $dispatcher;

	/**
	* @var ReconnectManager backoff/reconnect scheduling for the hub link
	*/
	public $reconnectManager;

	/**
	* @var V1Client PROTOCOL_V1 session/senders; dormant (legacy-only behavior)
	*                unless a local token file exists (the dual-running gate)
	*/
	public $v1;

	/**
	* @var PTYPool in-process registry of open pty.* sessions (PROTOCOL_V1 §2.3).
	*               Agent-local OS state (real pids/fds on THIS host) - never
	*               GlobalData. Reaped on hub disconnect via onClose() and
	*               periodically via the 'pty_reap' timer (see setupTimers()).
	*               NOTE: named `ptys` (not `ptyPool`) because every
	*               Handlers\V1\Pty*Handler reads $agent->ptys - keep this
	*               property name in sync with those call sites.
	*/
	public $ptys;

	public function __construct(?MessageDispatcher $dispatcher = null, ?ReconnectManager $reconnectManager = null, ?V1Client $v1 = null, ?PTYPool $ptys = null)
	{
		$this->dispatcher = $dispatcher !== null ? $dispatcher : new MessageDispatcher();
		$this->reconnectManager = $reconnectManager !== null ? $reconnectManager : new ReconnectManager();
		$this->v1 = $v1 !== null ? $v1 : new V1Client();
		$this->ptys = $ptys !== null ? $ptys : new PTYPool();
	}

	/**
	* worker boot: reset state, detect virtualization type, generate the self-signed cert
	* if missing, init the GlobalData client, load config, and open the ws(s) link to the hub
	*/
	public function onWorkerStart(Worker $worker)
	{
		$this->debug = true;
		$this->conn = null;
		$this->var = null;
		$this->vps_list = [];
		$this->bandwidth = null;
		$this->traffic_last = null;
		$this->timers = [];
		$this->ipmap = [];
		$this->running = [];
		$this->type = file_exists('/usr/sbin/vzctl') ? 'vzctl' : 'kvm';
		//Events::update_network_dev();
		$this->get_vps_ipmap();
		$this->hostname = isset($_SERVER['HOSTNAME']) ? $_SERVER['HOSTNAME'] : trim(shell_exec('hostname -f 2>/dev/null||hostname'));
		if (!file_exists(__DIR__.'/myadmin.crt')) {
			Worker::safeEcho('Generating new SSL Certificate for encrypted communications'.PHP_EOL);
			Worker::safeEcho(shell_exec('echo -e "US\nNJ\nSecaucus\nInterServer\nAdministration\n'.$this->hostname.'"|/usr/bin/openssl req -utf8 -batch -newkey rsa:2048 -keyout '.__DIR__.'/myadmin.key -nodes -x509 -days 365 -out '.__DIR__.'/myadmin.crt -set_serial 0'));
		}
		/**
		* @var \GlobalData\Client
		*/
		global $global;
		$global = new \GlobalData\Client('127.0.0.1:55553');	 // initialize the GlobalData client
		if (!isset($global->busy)) {
			$global->busy = 0;
		}
		$global->lastMessageTime = 0;
		// VPS_AGENT_CONFIG lets a test harness point at an alternate local config.ini
		// without touching the repo one; unset = exactly the old fixed path.
		$local_ini = getenv('VPS_AGENT_CONFIG') !== false ? getenv('VPS_AGENT_CONFIG') : __DIR__.'/../config.ini';
		$this->config = array_merge(parse_ini_file(__DIR__.'/../config.ini.dist', true), file_exists($local_ini) ? parse_ini_file($local_ini, true) : []);
		// v1 dual-running gate: reads [v1] token_file/host_id if present; with no
		// token file on disk the agent stays byte-identical legacy-only.
		$this->v1->configure($this->config);
		// hub endpoints are config-overridable ([hub] url / ssl_url) for harnesses;
		// defaults are the pre-3.5 hardcoded production endpoints.
		if ($this->config['options']['use_ssl'] == 1) {
			$ws_connection = new AsyncTcpConnection($this->config['hub']['ssl_url'] ?? 'ws://mynew.interserver.net:7272', $this->getSslContext());
			$ws_connection->transport = 'ssl';
		} else {
			$ws_connection = new AsyncTcpConnection($this->config['hub']['url'] ?? 'ws://mynew.interserver.net:7271');
		}
		$this->wireConnection($ws_connection);
		$ws_connection->connect();
	}

	/**
	* (re)attach all lifecycle callbacks + the built-in ws heartbeat to the hub
	* connection. Called before the initial connect() AND before every reconnect
	* attempt: Workerman v5.2's TcpConnection::destroy() nulls onMessage/onClose/
	* onError after emitting onClose (TcpConnection.php:1199, "Cleaning up the
	* callback to avoid memory leaks"), so a reconnect()ed connection would
	* otherwise come back with no callbacks and crash baseRead on the first frame.
	*/
	public function wireConnection(AsyncTcpConnection $ws_connection)
	{
		// Workerman v5 built-in ws protocol heartbeat: sends a protocol-level ping
		// control frame every N seconds after the handshake (Protocols/Ws.php).
		// This keeps NAT/firewall state alive at the transport layer; the
		// application-level {type:"ping"} JSON heartbeat (checkHeartbeat/sendPing)
		// remains the actual dead-connection detector, because protocol pong frames
		// are consumed inside the Ws protocol and never reach onMessage.
		$ws_connection->websocketPingInterval = (int)($this->config['heartbeat']['ws_ping_interval'] ?? 55);
		$ws_connection->onConnect = [$this, 'onConnect'];
		$ws_connection->onMessage = [$this, 'onMessage'];
		$ws_connection->onError = [$this, 'onError'];
		$ws_connection->onClose = [$this, 'onClose'];
		$ws_connection->onWorkerStop = [$this, 'onWorkerStop'];
	}

	/**
	* one reconnect attempt: re-wire the callbacks (see wireConnection) and let the
	* vendor AsyncTcpConnection::reconnect(0) reset the connection status back to
	* STATUS_INITIAL and connect() immediately - reusing the same connection object
	* avoids leaking failed connections in AsyncTcpConnection::$connections (a
	* CONNECT_FAIL never runs destroy(), so a fresh object per attempt would linger
	* in that static registry forever).
	*/
	public function reconnectToHub(AsyncTcpConnection $ws_connection)
	{
		$this->wireConnection($ws_connection);
		$ws_connection->reconnect(0);
	}

	/**
	* onConnect event for websocket connection to hub. v1 mode (local token file
	* present - the dual-running gate): sends auth.hello as the mandatory first
	* frame (PROTOCOL_V1 §2.1/§3). No token: the legacy {type:"login"} request,
	* byte-identical to the pre-3.5 agent.
	*/
	public function onConnect(AsyncTcpConnection $conn)
	{
		// fresh connection: any previous v1 session/pending-reply state is void
		$this->v1->resetSession();
		if ($this->v1->enabled()) {
			/**
			* @var \GlobalData\Client
			*/
			global $global;
			$global->lastMessageTime = time();
			$this->conn = $conn;
			$this->v1->sendHello($this, $conn);
			return;
		}
		$json = [
			'type' => 'login',
			'name' => $this->hostname,
			'module' => 'vps',
			'room_id' => 1,
			'ima' => 'host',
		];
		/**
		* @var \GlobalData\Client
		*/
		global $global;
		// fresh (re)connection: give it a full heartbeat-timeout window before
		// checkHeartbeat can declare it stale, instead of judging it by the
		// lastMessageTime left over from before the disconnect
		$global->lastMessageTime = time();
		$this->conn = $conn;
		$this->conn->send(json_encode($json));
	}

	/**
	* build the log-safe representation of a raw inbound frame (LOGGING ONLY -
	* the frame handed to the dispatchers is never modified). Two cases:
	*   1. v1 config.token envelopes: the data field carries the bearer token,
	*      and when the envelope uses enc:"gzip" it is a base64 blob that is
	*      trivially reversible (base64_decode + gzuncompress), so the entire
	*      data field is replaced with "[REDACTED]" regardless of encoding
	*      rather than trying to selectively redact inside an opaque blob.
	*   2. any other frame with a plaintext "token" JSON field keeps the
	*      existing value-level redaction. Everything else logs unchanged.
	*/
	private static function redactFrameForLog($raw)
	{
		if (!is_string($raw)) {
			return var_export($raw, true);
		}
		// cheap substring guard so the common path never pays a json_decode
		if (strpos($raw, 'config.token') !== false) {
			$decoded = json_decode($raw, true);
			if (is_array($decoded) && isset($decoded['op']) && $decoded['op'] === 'config.token') {
				if (array_key_exists('data', $decoded)) {
					$decoded['data'] = '[REDACTED]';
				}
				return json_encode($decoded);
			}
		}
		return strpos($raw, '"token"') !== false ? preg_replace('/"token"\s*:\s*"[^"]*"/', '"token":"[REDACTED]"', $raw) : $raw;
	}

	/**
	* raw ws frame from the hub: refresh lastMessageTime, json-decode, guard against a
	* malformed/non-JSON frame (mirroring the old default "Unhandled Mesage Type" fallthrough
	* rather than throwing a TypeError into MessageDispatcher), then dispatch by type.
	*/
	public function onMessage(AsyncTcpConnection $conn, $data)
	{
		$this->conn = $conn;
		// never log bearer-token material (AUTH_DESIGN §3: auth/token frames
		// must be redacted) - everything else logs exactly as before
		Worker::safeEcho('onMessage Got: '.self::redactFrameForLog($data).PHP_EOL);
		/**
		* @var \GlobalData\Client
		*/
		global $global;
		$global->lastMessageTime = time();
		// an application frame round-tripped from the hub - the link is confirmed
		// live, so reset the reconnect backoff to its base delay (no-op when the
		// attempt counter is already zero). Done here rather than in onConnect so
		// a hub that accepts the socket but dies before serving traffic cannot
		// collapse the backoff into a fast reconnect storm.
		$this->reconnectManager->confirmConnected();
		$data = json_decode($data, true);
		// a v1 envelope ({v:1,id,op,ts,data} request or {v:1,re,ok,...} reply) can
		// never be a valid legacy frame (no 'type'), so detecting it first leaves
		// the legacy dispatch path byte-unchanged. Routed even without a local
		// token so the config.token bootstrap push (AUTH_DESIGN §3) is receivable
		// over a legacy-authenticated connection.
		if (V1Envelope::isV1($data)) {
			$this->v1->onEnvelope($this, $conn, $data);
			return;
		}
		if (!is_array($data) || !isset($data['type'])) {
			// malformed/non-JSON frame - mirror the old onMessage.php default case
			// (which PHP-warned and fell through to the same log line) instead of
			// letting MessageDispatcher's array type hint throw a TypeError
			Worker::safeEcho("Unhandled Mesage Type \n");
			return;
		}
		$this->dispatcher->dispatch($this, $conn, $data);
	}

	/**
	* hub link dropped: schedule an exponential-backoff reconnect instead of the
	* old Worker::stopAll() (which killed the whole process and needed an external
	* supervisor such as systemd to bring the agent back). The process now stays
	* alive and self-heals - a hub bounce or network blip just means a few
	* [Reconnect] log lines and a re-login once the hub is reachable again.
	*/
	public function onClose(AsyncTcpConnection $conn)
	{
		Worker::safeEcho('Connection Closed, scheduling reconnect.'.PHP_EOL);
		// Phase 2 carried-forward item [2.4 LOW-1]: a dropped hub link must
		// never leak an open pty child past reconnect - kill every tracked
		// pty.* session immediately (same rationale/placement as the
		// ReconnectManager wiring added here in step 3.4, see BASELINE §9).
		$this->ptys->closeAll();
		$this->reconnectManager->scheduleReconnect(function () use ($conn) {
			$this->reconnectToHub($conn);
		}, 'connection closed');
	}

	/**
	* periodic sweep (wired via the 'pty_reap' timer in setupTimers()) that
	* removes any pty session whose child has already exited on its own
	* (crashed, or exited without a hub-side pty.close ever arriving) - see
	* PTYPool::reap() and the Phase 2 carried-forward item [2.4 LOW-1].
	*/
	public function pty_reap()
	{
		$this->ptys->reap();
	}

	/**
	* connection error callback. On an outright connect FAILURE (CONNECT_FAIL: hub
	* unreachable/refused) this ALSO schedules a reconnect, as defense-in-depth.
	* Empirically, the installed Workerman v5.2.x does fire onClose after a failed
	* connect (via destroy()), so onClose alone would suffice - an earlier comment
	* here wrongly claimed onClose was skipped and was corrected during review. The
	* redundant scheduling is kept anyway: it is provably harmless because
	* ReconnectManager's `scheduled` dedup flag turns a double-schedule for the same
	* drop into a no-op, and it keeps recovery robust regardless of any future
	* Workerman-version behavior change. Errors on an already-established connection
	* are left to onClose (which runs when destroy() tears the link down).
	*/
	public function onError(AsyncTcpConnection $connection, $code, $msg)
	{
		Worker::safeEcho("error: {$msg}\n");
		// Defense-in-depth: on an outright connect FAILURE (hub unreachable/
		// refused) Workerman v5 fires onError with CONNECT_FAIL and then
		// destroy()s the connection, which also fires onClose. Scheduling here
		// too is deliberately redundant - scheduleReconnect() dedupes when both
		// callbacks fire for the same drop - so reconnection stays robust even
		// if a Workerman version skips onClose on a failed connect. Errors on
		// an established connection are handled by onClose via destroy().
		if ($code === \Workerman\Connection\ConnectionInterface::CONNECT_FAIL) {
			$this->reconnectManager->scheduleReconnect(function () use ($connection) {
				$this->reconnectToHub($connection);
			}, 'connect failed: '.$msg);
		}
	}

	public function onWorkerStop(Worker $worker)
	{
		/**
		* @var \GlobalData\Client
		*/
		global $global;
		if (($settings['vmstat']['enable'] ?? null) === TRUE) {
			@shell_exec('killall vmstat');
			@pclose($worker->process_handle);
		}
	}

	/**
	* ran periodically (check_interval timer) to detect a dead/stuck hub link via the
	* APPLICATION-level ping/pong exchange - the only heartbeat that can observe an
	* application hang, since Workerman v5's built-in ws protocol pongs (see
	* wireConnection) are consumed inside the Ws protocol and never reach onMessage.
	*
	* Guard: skips entirely unless the connection is a live, ESTABLISHED
	* AsyncTcpConnection - while disconnected or mid-reconnect there is nothing to
	* ping and nothing to close, and the ReconnectManager owns recovery until the
	* link is back up.
	*
	* On timeout it now just close()s the connection (was close() + Worker::stopAll());
	* the close funnels into onClose -> ReconnectManager::scheduleReconnect(), the same
	* backoff path used for every other connection loss, so no process death / external
	* supervisor is needed.
	*/
	public function checkHeartbeat()
	{
		//Worker::safeEcho("[HeartBeat] Check starting".PHP_EOL);
		/**
		* @var \GlobalData\Client
		*/
		global $global;
		// while disconnected / mid-reconnect there is nothing to ping and nothing
		// to close - the ReconnectManager owns recovery until the link is back up
		if (!($this->conn instanceof AsyncTcpConnection) || $this->conn->getStatus() !== \Workerman\Connection\TcpConnection::STATUS_ESTABLISHED) {
			return;
		}
		$timeSinceMessage = time() - $global->lastMessageTime;
		if ($timeSinceMessage >= $this->config['heartbeat']['timeout']) {
			// stale/dead connection: close it and let onClose funnel into the
			// same ReconnectManager backoff path (was close() + Worker::stopAll())
			Worker::safeEcho("Time Since Last Message {$timeSinceMessage}, Closing Connection".PHP_EOL);
			$this->conn->close();
		} elseif ($timeSinceMessage >= $this->config['heartbeat']['ping_when_silent_for']) {
			//Worker::safeEcho("Time Since Last Message {$timeSinceMessage}, Sending Ping".PHP_EOL);
			$this->sendPing();
		}
	}

	public function sendPing()
	{
		$this->conn->send(json_encode(['type' => 'ping'])); // send ping request to hub
	}

	public function sendPong()
	{
		$this->conn->send(json_encode(['type' => 'pong'])); // send pong request to hub
	}

	/**
	* register the periodic host timers (vps_update_info / traffic / list / heartbeat)
	* and prime an immediate vps_update_info + get_map request
	*/
	public function setupTimers()
	{
		$this->vps_update_info();
		$this->get_map_timer();
		$this->addTimer('vps_update_info');
		$this->addTimer('vps_get_traffic');
		$this->addTimer('vps_get_list');
		$this->addTimer('check_interval', $this->config['heartbeat']['check_interval'], [$this, 'checkHeartbeat']);
		// Phase 2 carried-forward item [2.4 LOW-1]: periodic orphaned-pty sweep,
		// independent of the onClose-triggered closeAll() - catches a pty child
		// that exited/crashed on its own without any pty.close ever arriving
		// (e.g. a hub-side bug), on a fixed 60s cadence not tied to config.ini
		// so this reaper is always live even on an unmodified config.
		$this->addTimer('pty_reap', 60, [$this, 'pty_reap']);
	}

	/**
	* (re)register a named Workerman\Timer; deletes any prior timer of the same name first.
	* Defaults the callable to [$this, $name] and the interval to config['timers'][$name].
	*/
	public function addTimer($name, $interval = false, $callable = false)
	{
		if ($callable === false) {
			$callable = [$this, $name];
		}
		if ($interval === false) {
			$interval = $this->config['timers'][$name];
		}
		Worker::safeEcho("addTimer called with ({$name}, <callable>, ".var_export($interval, true).") called timer set? ".var_export(isset($this->timers[$name]), true).PHP_EOL);
		if (isset($this->timers[$name])) {
			Worker::safeEcho("addTimer deleting timer {$name} id {$this->timers[$name]}\n");
			Timer::del($this->timers[$name]);
		}
		$this->timers[$name] = Timer::add($interval, $callable, []);
		Worker::safeEcho("addTimer adding timer {$name} every {$interval} got timer id {$this->timers[$name]}\n");
	}

	/**
	* validate an IPv4 (optionally IPv6) address string
	*/
	public function validIp($ip, $display_errors = true, $support_ipv6 = false)
	{
		if (version_compare(PHP_VERSION, '5.2.0') >= 0) {
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
				if ($support_ipv6 === false || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
					return false;
				}
			}
		} else {
			if (!preg_match("/^[0-9\.]{7,15}$/", $ip)) {
				// don't display errors cuz this gets called w/ a blank entry when people didn't even submit anything yet
				//add_output('<font class="error">IP '.$ip.' Too short/long</font>');
				return false;
			}
			$quads = explode('.', $ip);
			$numquads = count($quads);
			if ($numquads != 4) {
				if ($display_errors) {
					error_log('<font class="error">IP '.$ip.' Too many quads</font>');
				}
				return false;
			}
			for ($i = 0; $i < 4; $i++) {
				if ($quads[$i] > 255) {
					if ($display_errors) {
						error_log('<font class="error">IP '.$ip.' number '.$quads[$i].' too high</font>');
					}
					return false;
				}
			}
		}
		return true;
	}

	/**
	* build the AsyncTcpConnection ssl context (used only when config option use_ssl == 1).
	* See the inline note re: the cert-path correction ported from the old getSslContext closure.
	*/
	public function getSslContext()
	{
		// note: the pre-refactor code pointed these at src/Events/myadmin.crt|key, which never
		// existed (the certificate is generated one directory up, in src/) - corrected to the
		// generated location.
		return [
			'ssl' => [ // use the absolute/full paths
				'local_cert' => __DIR__.'/myadmin.crt',
				'local_pk' => __DIR__.'/myadmin.key',
				'verify_peer' => false,
				'verify_peer_name' => false,
			]
		];
	}

	/**
	* ran periodically to request updated vps mapping files from the hub
	*/
	public function get_map_timer()
	{
		/**
		* @var \GlobalData\Client
		*/
		global $global;
		if ($this->v1->isActive()) {
			// v1: config.maps pull (§2.6); the reply is applied through the same
			// GetMapHandler file-writing logic (byte-compat invariant)
			$this->v1->requestMaps($this, $this->conn);
			return;
		}
		/** send get_map request to hub **/
		$this->conn->send(json_encode(['type' => 'get_map']));
		/** release global lock **/
	}

	/**
	* build the ip => veid map for local VPS (kvm via dhcpd.vps, vzctl via vzlist)
	* and cache it on $this->ipmap
	*/
	public function get_vps_ipmap()
	{
		$dir = __DIR__.'/../../';
		if ($this->type == 'kvm') {
			$output = trim(`export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin"; if [ -e /etc/dhcp/dhcpd.vps ]; then DHCPVPS=/etc/dhcp/dhcpd.vps; else DHCPVPS=/etc/dhcpd.vps; fi;  if [ -e \$DHCPVPS ]; then grep "^host" \$DHCPVPS | tr \; " " | awk '{ print $2 " " $8 }'; fi;`);
		} else {
			$output = rtrim(`/usr/sbin/vzlist -H -o veid,ip 2>/dev/null`);
		}
		$lines = explode("\n", $output);
		$ipmap = [];
		foreach ($lines as $line) {
			$parts = explode(' ', trim($line));
			if (sizeof($parts) > 1) {
				$id = $parts[0];
				$ip = $parts[1];
				if ($this->validIp($ip, false) == true) {
					$extra = trim(`touch {$dir}/vps.ipmap ; export PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";grep "^$ip:" {$dir}/vps.ipmap | cut -d: -f2`);
					if ($extra != '') {
						$parts = array_merge($parts, explode("\n", $extra));
					}
					for ($x = 1; $x < sizeof($parts); $x++) {
						if ($parts[$x] != '-') {
							$ipmap[$parts[$x]] = $id;
						}
					}
				}
			}
		}
		$this->ipmap = $ipmap;
		return $ipmap;
	}

	/**
	* compute per-VPS in/out byte deltas since the last sample (kvm via /proc/net/dev vnet
	* counters, vzctl via iptables FORWARD counters); caches the result on $this->bandwidth
	*/
	public function get_vps_iptables_traffic()
	{
		//Worker::safeEcho("get_vps_iptables_traffic [0] Starting up processing for type '{$this->type}'\n");
		$totals = [];
		if ($this->type == 'kvm') {
			if (is_null($this->traffic_last) && file_exists('/root/.traffic.last')) {
				$this->traffic_last = json_decode(file_get_contents('/root/.traffic.last'), true);
				if (is_null($this->traffic_last) && $this->traffic_last === false) {
					$this->traffic_last = unserialize(file_get_contents('/root/.traffic.last'));
				}
			}
			$vnetcounters = trim(`grep vnet /proc/net/dev | tr : " " | awk '{ print $1 " " $2 " " $10 }'`);
			if ($vnetcounters != '') {
				$vnetcounters = explode("\n", $vnetcounters);
				$vnets = [];
				foreach ($vnetcounters as $line) {
					list($vnet, $out, $in) = explode(' ', $line);
					//Worker::safeEcho("get_vps_iptables_traffic [1] Got    VNet:$vnet   IN:$in    OUT:$out\n");
					$vnets[$vnet] = ['in' => $in, 'out' => $out];
				}
				$cmd = 'grep -H -i fe /sys/devices/virtual/net/vnet*/address 2>/dev/null| sed s#"/sys/devices/virtual/net/\([^/]*\)/address:fe:\(.*\)$"#"\1 52:\2"#g';
				$vnetmacs = trim(`$cmd`);
				if ($vnetmacs != '') {
					$vnetmacs = explode("\n", $vnetmacs);
					$macs = [];
					foreach ($vnetmacs as $line) {
						list($vnet, $mac) = explode(' ', $line);
						$mac = preg_replace('/^52:16:3e:/', '00:16:3e:', $mac);
						//Worker::safeEcho("get_vps_iptables_traffic [2] Got  VNet:$vnet   Mac:$mac\n");
						$vnets[$vnet]['mac'] = $mac;
						$macs[$mac] = $vnet;
					}
					$cmd = 'if [ -e /etc/dhcp/dhcpd.vps ]; then cat /etc/dhcp/dhcpd.vps; else cat /etc/dhcpd.vps; fi | grep ethernet | sed s#"^host \([a-z0-9\.]*\) { hardware ethernet \([^;]*\); fixed-address \([0-9\.]*\);}$"#"\2 \1 \3"#g';
					$macvps = explode("\n", trim(`$cmd`));
					$vpss = [];
					foreach ($macvps as $line) {
						list($mac, $vps, $ip) = explode(' ', $line);
						//Worker::safeEcho("get_vps_iptables_traffic [3] Got  Mac:$mac   VPS:$vps   IP:$ip\n");
						if (isset($macs[$mac]) && isset($vnets[$macs[$mac]])) {
							$vpss[$vps] = $vnets[$macs[$mac]];
							$vpss[$vps]['ip'] = $ip;
							if (isset($last) && isset($vpss[$vps])) {
								$in_new = bcsub($vpss[$vps]['in'], $last[$vps]['in'], 0);
								$out_new = bcsub($vpss[$vps]['out'], $last[$vps]['out'], 0);
							} elseif (isset($last)) {
								$in_new = $last[$vps]['in'];
								$out_new = $last[$vps]['out'];
							} else {
								$in_new = $vpss[$vps]['in'];
								$out_new = $vpss[$vps]['out'];
							}
							if ($in_new > 0 || $out_new > 0) {
								$totals[$ip] = ['vps' => $vps, 'in' => $in_new, 'out' => $out_new];
							}
						}
					}
					if (sizeof($totals) > 0) {
						$this->traffic_last = $vpss;
						file_put_contents('/root/.traffic.last', json_encode($vpss));
					}
				}
			}
		} else {
			foreach ($this->ipmap as $ip => $id) {
				if ($this->validIp($ip, false) == true) {
					$lines = explode("\n", trim(`/sbin/iptables -nvx -L FORWARD 2>/dev/null | grep -v DROP  | awk '{ print " " $7 " " $8 " " $2 }' | grep -vi "[a-z]" | sort -n | grep " $ip " | awk '{ print $3 }'`));
					if (sizeof($lines) == 2) {
						list($in, $out) = $lines;
						$total = $in + $out;
						if ($total > 0) {
							$totals[$ip] = ['vps' => $id, 'in' => $in, 'out' => $out];
						}
					}
				}
			}
			`PATH="/usr/local/bin:/usr/local/sbin:\$PATH:/sbin:/usr/sbin"  iptables -Z`;
			$this->vps_iptables_traffic_rules();
		}
		$this->bandwidth = $totals;
		return $totals;
	}

	/**
	* (re)install per-IP iptables FORWARD accounting rules so byte counters can be sampled
	*/
	public function vps_iptables_traffic_rules()
	{
		$cmd = '';
		foreach ($this->ipmap as $ip => $id) {
			$cmd .= '/sbin/iptables -D FORWARD -d '.$ip.' 2>/dev/null;';
			$cmd .= '/sbin/iptables -D FORWARD -s '.$ip.' 2>/dev/null;';
			// run it twice to be safe
			$cmd .= '/sbin/iptables -D FORWARD -d '.$ip.' 2>/dev/null;';
			$cmd .= '/sbin/iptables -D FORWARD -s '.$ip.' 2>/dev/null;';
			$cmd .= '/sbin/iptables -A FORWARD -d '.$ip.';';
			$cmd .= '/sbin/iptables -A FORWARD -s '.$ip.';';
		}
		`$cmd`;
	}

	/**
	* dispatch vps_get_cpu to the local TaskWorker and forward its result to the hub
	*/
	public function vps_get_cpu()
	{
		/**
		* @var \GlobalData\Client
		*/
		global $global;
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
		$task_connection->send(json_encode(['type' => 'vps_get_cpu', 'args' => ['type' => $this->type]]));
		$conn = $this->conn;
		$task_connection->onMessage = function ($task_connection, $task_result) use ($conn) {
			//var_dump($task_result);
			$task_connection->close();
			if ($this->v1->isActive()) {
				// v1: telemetry.cpu {host, per_vps} (§2.5) - the host entry is the
				// index-0 element of the legacy map, split out per the frozen spec.
				// (Tasks/vps_get_cpu.php currently returns null due to a pre-existing
				// upstream bug - nothing is sent until that task is fixed, matching
				// the legacy path which would forward "null" uselessly.)
				$decoded = json_decode($task_result, true);
				$map = is_array($decoded) ? ($decoded['content'] ?? $decoded) : null;
				if (is_array($map) && count($map) > 0) {
					$host = $map[0] ?? [];
					unset($map[0]);
					$conn->send(json_encode(V1Envelope::request('telemetry.cpu', [
						'host' => is_array($host) && count($host) > 0 ? $host : new \stdClass(),
						'per_vps' => count($map) > 0 ? $map : new \stdClass()
					])));
				}
				return;
			}
			$conn->send($task_result);
		};
		$task_connection->connect();
	}

	/**
	* gets a listing of vps services to send to the hub
	*/
	public function vps_get_list()
	{
		/**
		* @var \GlobalData\Client
		*/
		global $global;
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
		$task_connection->send(json_encode(['type' => 'vps_get_list', 'lock' => true, 'args' => ['type' => $this->type]]));
		$conn = $this->conn;
		if ($this->debug === true) {
			Worker::safeEcho('vps_get_list Launching Task Processor'.PHP_EOL);
		}
		$task_connection->onMessage = function ($task_connection, $task_result) use ($conn) {
			/**
			* @var \GlobalData\Client
			*/
			global $global;
			if ($this->debug === true) {
				Worker::safeEcho('vps_get_list Got Task Processor Result, Closing Task Connection'.PHP_EOL);
			}
			//Worker::safeEcho(var_dump($task_result,true));
			$task_connection->close();
			//Worker::safeEcho('Get List Got Result, Forwarding It'.PHP_EOL);
			if ($this->v1->isActive()) {
				// v1: telemetry.inventory {servers, host, ips} (§2.5) - the legacy
				// servers[0] host pseudo-entry is promoted to the sibling 'host' key
				// per the frozen spec; large payload so enc:"gzip". The cpu_flags/
				// speed pair inside host.os_info additionally feeds
				// telemetry.host_extra (the v1 form of server_info_extra).
				$decoded = json_decode($task_result, true);
				$content = is_array($decoded) ? ($decoded['content'] ?? null) : null;
				if (is_array($content)) {
					$servers = isset($content['servers']) && is_array($content['servers']) ? $content['servers'] : [];
					$ips = isset($content['ips']) && is_array($content['ips']) ? $content['ips'] : [];
					$host = $servers[0] ?? [];
					unset($servers[0]);
					$conn->send(json_encode(V1Envelope::request('telemetry.inventory', [
						'servers' => count($servers) > 0 ? $servers : new \stdClass(),
						'host' => is_array($host) && count($host) > 0 ? $host : new \stdClass(),
						'ips' => count($ips) > 0 ? $ips : new \stdClass()
					], true)));
					if (isset($host['os_info']['cpu_flags'], $host['os_info']['speed'])) {
						$conn->send(json_encode(V1Envelope::request('telemetry.host_extra', [
							'cpu_flags' => $host['os_info']['cpu_flags'],
							'speed' => $host['os_info']['speed']
						])));
					}
				}
				return;
			}
			$conn->send($task_result);
			//Worker::safeEcho('Get List Timer End'.PHP_EOL);
		};
		$task_connection->connect();
	}

	/**
	* sample per-VPS traffic and, if any, send it to the hub as a {type:"bandwidth"} frame
	*/
	public function vps_get_traffic()
	{
		//Worker::safeEcho("vps_get_traffic [0] called, calling get_vps_iptables_traffic\n");
		$totals = $this->get_vps_iptables_traffic();
		//Worker::safeEcho("vps_get_traffic [1] get_vps_iptables_traffic returned ".var_export($totals, true).PHP_EOL);
		if (sizeof($totals) > 0) {
			if ($this->v1->isActive()) {
				// v1: telemetry.bandwidth {per_ip: ip => {vps, in, out}} (§2.5) -
				// same keyed-by-IP map the legacy {type:"bandwidth"} frame carried
				$this->conn->send(json_encode(V1Envelope::request('telemetry.bandwidth', ['per_ip' => $totals])));
				return;
			}
			$this->conn->send(json_encode([
				'type' => 'bandwidth',
				'content' => $totals,
			]));
		}
	}

	/**
	* dispatch the queued vps_queue commands (from global settings) to the local TaskWorker
	*/
	public function vps_queue_timer()
	{
		/**
		* @var \GlobalData\Client
		*/
		global $global;
		$cmds = $global->settings['vps_queue']['cmds'];
		if ($this->v1->isActive()) {
			// v1: the queue-fetching actions move onto the WS link - 'get_queue'
			// becomes queue.pull (§2.4 AMENDMENT 2: single aggregate script entry)
			// and 'get_new_vps' becomes queue.provision; every other queued cmd
			// (local php scripts / shell paths / other HTTP actions) still goes
			// through the unchanged Tasks/vps_queue.php path below.
			$remaining = [];
			foreach ($cmds as $cmd) {
				if ($cmd === 'get_queue') {
					$this->v1->queuePull($this, $this->conn);
				} elseif ($cmd === 'get_new_vps') {
					$this->v1->queueProvision($this, $this->conn);
				} else {
					$remaining[] = $cmd;
				}
			}
			$cmds = $remaining;
			if (count($cmds) === 0) {
				return;
			}
		}
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552'); // Asynchronous link with the remote task service
		$task_connection->send(json_encode(['type' => 'vps_queue', 'args' => $cmds])); // send data
		$conn = $this->conn;
		$task_connection->onMessage = function ($task_connection, $task_result) use ($conn) {
			//var_dump($task_result);
			$task_connection->close();
			//$conn->send($task_result);
		};
		$task_connection->connect(); // execute async link
	}

	/**
	* dispatch vps_update_info to the local TaskWorker and forward its result to the hub
	*/
	public function vps_update_info()
	{
		/**
		* @var \GlobalData\Client
		*/
		global $global;
		//echo 'Update Info Timer Startup'.PHP_EOL;
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
		$task_connection->send(json_encode(['type' => 'vps_update_info', 'lock' => true, 'args' => ['type' => $this->type]]));
		$conn = $this->conn;
		$task_connection->onMessage = function ($task_connection, $task_result) use ($conn) {
			/**
			* @var \GlobalData\Client
			*/
			global $global;
			//Worker::safeEcho(var_dump($task_result,true));
			$task_connection->close();
			//Worker::safeEcho('Update Info Got Result, Forwarding It'.PHP_EOL);
			if ($this->v1->isActive()) {
				// v1: telemetry.host (§2.5) - data is the FLAT server object the
				// task builds (legacy nests it as {type:"vps_info", content:{server}})
				$decoded = json_decode($task_result, true);
				$server = is_array($decoded) ? ($decoded['content']['server'] ?? null) : null;
				if (is_array($server)) {
					$conn->send(json_encode(V1Envelope::request('telemetry.host', $server)));
				}
				return;
			}
			$conn->send($task_result);
			//Worker::safeEcho('Update Info Timer End'.PHP_EOL);
		};
		$task_connection->connect();
	}

	/**
	* dispatch async_hyperv_get_list to the local TaskWorker (fire-and-forget)
	*/
	public function vps_update_info_timer()
	{
		/**
		* @var \GlobalData\Client
		*/
		global $global;
		$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
		$task_connection->send(json_encode(['type' => 'async_hyperv_get_list', 'args' => []]));
		$conn = $this->conn;
		$task_connection->onMessage = function ($task_connection, $task_result) use ($conn) {
			//var_dump($task_result);
			$task_connection->close();
			//$conn->send($task_result);
		};
		$task_connection->connect();
	}
}
