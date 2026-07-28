<?php

namespace MyAdmin\VpsHost\Tests;

use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\PTYPool;
use MyAdmin\VpsHost\V1Envelope;
use MyAdmin\VpsHost\Handlers\V1\PtyOpenHandler;
use MyAdmin\VpsHost\Handlers\V1\PtyDataHandler;
use MyAdmin\VpsHost\Handlers\V1\PtyResizeHandler;
use MyAdmin\VpsHost\Handlers\V1\PtyCloseHandler;
use MyAdmin\VpsHost\Tests\Fakes\FakeConnection;
use Workerman\Worker;
use Workerman\Events\Select;

/**
* Handler-level coverage for the four pty.* v1 handlers, driving handle()
* directly with a FakeConnection (this repo's existing send()-capturing test
* double) and a real Agent carrying a real PTYPool. Asserts the exact
* reply/relay envelope shapes against datacentered docs/PROTOCOL_V1.md §2.3:
*
*   pty.open   -> reply {v,re,ok:true,data:{pty_id}}
*   pty.data   -> no reply; base64 payload decoded and written to the pty
*   pty.resize -> no reply; real resize applied
*   pty.close  -> no reply; child killed + dropped from pool
*   pty.data   (agent->hub relay) -> request {op:"pty.data", data:{pty_id, data:base64}}
*   pty.close  (agent-self on EOF) -> request {op:"pty.close", data:{pty_id[, code]}}
*                                     with `code` OMITTED when null (array_filter)
*
* Plus the documented error/edge paths: missing/duplicate/unknown pty_id,
* malformed input - all handled gracefully (bad_request or silent no-op, never
* a fatal). A live Workerman Select loop is installed because PtyOpenHandler
* registers the read watcher via watchStdout() and PtyCloseHandler calls
* Worker::getEventLoop()->offReadable() directly.
*/
class PtyHandlersTest extends AgentTestCase
{
	/** @var Select */
	private $event;
	/** @var mixed */
	private $prevGlobalEvent;
	/** @var Agent */
	private $agent;

	protected function setUp(): void
	{
		parent::setUp();
		$ref = new \ReflectionProperty(Worker::class, 'globalEvent');
		$this->prevGlobalEvent = $ref->getValue();
		$this->event = new Select();
		$ref->setValue(null, $this->event);
		$this->agent = new Agent(null, null, null, new PTYPool());
		$this->agent->conn = new FakeConnection();
	}

	protected function tearDown(): void
	{
		$this->agent->ptys->closeAll();
		(new \ReflectionProperty(Worker::class, 'globalEvent'))->setValue(null, $this->prevGlobalEvent);
		parent::tearDown();
	}

	/** build a decoded v1 request envelope as V1MessageDispatcher would hand to a handler */
	private function req(string $op, array $data): array
	{
		return V1Envelope::request($op, $data);
	}

	// ---------------------------------------------------------------- pty.open

	public function testOpenAllocatesPtyAndRepliesWithPtyId(): void
	{
		$conn = new FakeConnection();
		$env = $this->req('pty.open', ['pty_id' => 'p-open-1', 'command' => 'cat', 'cols' => 100, 'rows' => 40]);
		(new PtyOpenHandler())->handle($this->agent, $conn, $env);

		$decoded = $conn->lastDecoded();
		$this->assertSame(1, $decoded['v']);
		$this->assertSame($env['id'], $decoded['re'], 'reply must correlate by re');
		$this->assertTrue($decoded['ok']);
		$this->assertSame(['pty_id' => 'p-open-1'], $decoded['data'], 'reply data is exactly {pty_id}');
		$this->assertArrayNotHasKey('op', $decoded, 'a reply carries no op');

		$session = $this->agent->ptys->get('p-open-1');
		$this->assertNotNull($session, 'session must be registered in the pool');
		$this->assertTrue($session->isRunning());
		$this->assertSame(100, $session->cols);
		$this->assertSame(40, $session->rows);
	}

	public function testOpenDefaultsGeometryWhenOmitted(): void
	{
		$conn = new FakeConnection();
		(new PtyOpenHandler())->handle($this->agent, $conn, $this->req('pty.open', ['pty_id' => 'p-def', 'command' => 'cat']));
		$s = $this->agent->ptys->get('p-def');
		$this->assertSame(80, $s->cols, 'default cols=80');
		$this->assertSame(24, $s->rows, 'default rows=24');
	}

	public function testOpenMissingPtyIdRepliesBadRequest(): void
	{
		$conn = new FakeConnection();
		(new PtyOpenHandler())->handle($this->agent, $conn, $this->req('pty.open', ['command' => 'cat']));
		$decoded = $conn->lastDecoded();
		$this->assertFalse($decoded['ok']);
		$this->assertSame('bad_request', $decoded['error']['code']);
		$this->assertSame(0, $this->agent->ptys->count(), 'no session created on bad_request');
	}

	public function testOpenNonStringPtyIdRepliesBadRequest(): void
	{
		$conn = new FakeConnection();
		(new PtyOpenHandler())->handle($this->agent, $conn, $this->req('pty.open', ['pty_id' => 12345, 'command' => 'cat']));
		$decoded = $conn->lastDecoded();
		$this->assertFalse($decoded['ok']);
		$this->assertSame('bad_request', $decoded['error']['code']);
		$this->assertSame(0, $this->agent->ptys->count());
	}

	public function testDuplicateOpenRepliesBadRequestAndLeavesOriginalUntouched(): void
	{
		$conn1 = new FakeConnection();
		(new PtyOpenHandler())->handle($this->agent, $conn1, $this->req('pty.open', ['pty_id' => 'dup', 'command' => 'cat']));
		$orig = $this->agent->ptys->get('dup');
		$origPid = $orig->pid;

		$conn2 = new FakeConnection();
		(new PtyOpenHandler())->handle($this->agent, $conn2, $this->req('pty.open', ['pty_id' => 'dup', 'command' => 'cat']));
		$decoded = $conn2->lastDecoded();
		$this->assertFalse($decoded['ok']);
		$this->assertSame('bad_request', $decoded['error']['code']);
		$this->assertStringContainsString('already in use', $decoded['error']['message']);

		// original untouched: same object, same pid, still running, count still 1.
		$this->assertSame($orig, $this->agent->ptys->get('dup'));
		$this->assertSame($origPid, $this->agent->ptys->get('dup')->pid);
		$this->assertTrue($orig->isRunning());
		$this->assertSame(1, $this->agent->ptys->count());
	}

	// ------------------------------------------------- pty.open §5 scope gate

	public function testOpenEmptyCommandWithoutElevationIsForbidden(): void
	{
		// REGRESSION (review BUG 2 / PROTOCOL_V1 §5): an empty/absent command is
		// shell mode - the agent must fail CLOSED without data.elevated===true,
		// never spawn a login shell just because the hub relayed the frame.
		$conn = new FakeConnection();
		$env = $this->req('pty.open', ['pty_id' => 'gate1']);
		(new PtyOpenHandler())->handle($this->agent, $conn, $env);
		$decoded = $conn->lastDecoded();
		$this->assertFalse($decoded['ok']);
		$this->assertSame('forbidden', $decoded['error']['code']);
		$this->assertSame($env['id'], $decoded['re']);
		$this->assertSame(0, $this->agent->ptys->count(), 'NO shell session may be created on a refused open');
	}

	public function testOpenExplicitShellScopeWithoutElevationIsForbidden(): void
	{
		$conn = new FakeConnection();
		(new PtyOpenHandler())->handle($this->agent, $conn, $this->req('pty.open', ['pty_id' => 'gate2', 'scope' => 'shell']));
		$decoded = $conn->lastDecoded();
		$this->assertFalse($decoded['ok']);
		$this->assertSame('forbidden', $decoded['error']['code']);
		$this->assertSame(0, $this->agent->ptys->count());
	}

	public function testOpenShellScopeWithNonTrueElevationIsForbidden(): void
	{
		// truthy-but-not-true markers must NOT pass the gate (strict === true)
		foreach ([1, 'true', 'yes', []] as $i => $bogus) {
			$conn = new FakeConnection();
			(new PtyOpenHandler())->handle($this->agent, $conn, $this->req('pty.open', ['pty_id' => 'gate3-'.$i, 'scope' => 'shell', 'elevated' => $bogus]));
			$decoded = $conn->lastDecoded();
			$this->assertFalse($decoded['ok'], 'elevated='.var_export($bogus, true).' must not pass the gate');
			$this->assertSame('forbidden', $decoded['error']['code']);
		}
		$this->assertSame(0, $this->agent->ptys->count());
	}

	public function testOpenShellScopeWithElevationMarkerSpawnsLoginShell(): void
	{
		// with the explicit elevation marker the shell path must still work
		// (§5: elevated sessions ARE allowed; the gate is deny-by-default, not
		// deny-always).
		$conn = new FakeConnection();
		$env = $this->req('pty.open', ['pty_id' => 'gate-ok', 'scope' => 'shell', 'elevated' => true]);
		(new PtyOpenHandler())->handle($this->agent, $conn, $env);
		$decoded = $conn->lastDecoded();
		$this->assertTrue($decoded['ok'], 'elevated shell open must succeed');
		$this->assertSame(['pty_id' => 'gate-ok'], $decoded['data']);
		$s = $this->agent->ptys->get('gate-ok');
		$this->assertNotNull($s);
		$this->assertTrue($s->isRunning(), 'login shell must be running');
	}

	public function testOpenCommandScopeStillNeedsNoElevationMarker(): void
	{
		// the previously-working scope:"command" path (real non-empty command,
		// no elevated field) must be completely unaffected by the §5 gate.
		$conn = new FakeConnection();
		$env = $this->req('pty.open', ['pty_id' => 'gate-cmd', 'scope' => 'command', 'command' => 'cat']);
		(new PtyOpenHandler())->handle($this->agent, $conn, $env);
		$decoded = $conn->lastDecoded();
		$this->assertTrue($decoded['ok'], 'command-scope open must not require elevation');
		$this->assertTrue($this->agent->ptys->get('gate-cmd')->isRunning());
	}

	public function testOpenNeverMergesClientSuppliedEnvIntoTheChild(): void
	{
		// SECURITY REGRESSION (§2.3 "env allowlisted server-side" + the hub's own
		// "env dropped" decision, mirrored in PtyOpenHandler's docblock): an
		// attacker-controlled `env` in the envelope must NEVER reach the spawned
		// child (no LD_PRELOAD/PATH/BASH_ENV injection). Prove it empirically: the
		// child prints its own environment; the poisoned var must be absent.
		$conn = new FakeConnection();
		$poison = 'PTY_ENV_INJECTION_CANARY';
		$env = $this->req('pty.open', [
			'pty_id' => 'envsec',
			// `printenv` with no args dumps the child's full environment.
			'command' => 'stty raw -echo; printenv; sleep 0.2',
			'env' => [$poison => 'pwned', 'LD_PRELOAD' => '/tmp/evil.so'],
		]);
		(new PtyOpenHandler())->handle($this->agent, $conn, $env);

		// drain the child's output off its own slave until it finishes.
		$s = $this->agent->ptys->get('envsec');
		$got = '';
		$deadline = microtime(true) + 2.0;
		while (microtime(true) < $deadline) {
			$chunk = @fread($s->readStream(), 65536);
			if ($chunk !== false && $chunk !== '') {
				$got .= $chunk;
			} else {
				usleep(20000);
			}
			if (!$s->isRunning() && $got !== '') {
				break;
			}
		}
		$this->assertStringNotContainsString($poison, $got, 'client-supplied env var must NOT be in the child environment');
		$this->assertStringNotContainsString('/tmp/evil.so', $got, 'client-supplied LD_PRELOAD must NOT be in the child environment');
		// sanity: printenv did run and produce SOMETHING (so the absence above is
		// meaningful, not just an empty read) - PATH is always inherited from $_SERVER.
		$this->assertStringContainsString('PATH=', $got, 'printenv must have actually emitted the (server-side) environment');
	}

	public function testOpenStreamsChildOutputAsPtyDataRelayFrames(): void
	{
		// open a pty that emits a known marker; drive the loop; assert the agent
		// relays it as an outbound pty.data REQUEST with base64 payload.
		$conn = new FakeConnection();
		(new PtyOpenHandler())->handle($this->agent, $conn, $this->req('pty.open', ['pty_id' => 'stream', 'command' => "printf 'HELLO-PTY'"]));

		// first frame sent is the ok reply; subsequent frames are pty.data relays.
		$this->event->delay(2.0, function () {
			$this->event->stop();
		});
		// stop as soon as we observe a pty.data frame containing our marker.
		$deadline = microtime(true) + 2.0;
		while (microtime(true) < $deadline) {
			$this->event->run(); // Select::run returns when stopped
			break;
		}

		$relayed = null;
		foreach ($conn->sent as $raw) {
			$f = json_decode($raw, true);
			if (isset($f['op']) && $f['op'] === 'pty.data') {
				$relayed = $f;
				break;
			}
		}
		$this->assertNotNull($relayed, 'agent must relay child output as a pty.data request frame');
		$this->assertSame('stream', $relayed['data']['pty_id']);
		$this->assertSame('HELLO-PTY', base64_decode($relayed['data']['data']), 'relayed data is base64 of the raw child bytes');
	}

	public function testOpenEmitsSelfPtyCloseOnEofWithCodeWhenChildExits(): void
	{
		// `printf ...; exit 7` - after output+EOF the onEof callback must emit a
		// pty.close request; code may or may not be captured depending on timing,
		// but when present it is an int and never null-serialized.
		$conn = new FakeConnection();
		(new PtyOpenHandler())->handle($this->agent, $conn, $this->req('pty.open', ['pty_id' => 'eofclose', 'command' => "printf X; exit 7"]));

		$this->event->delay(2.0, function () {
			$this->event->stop();
		});
		$this->event->run();

		$close = null;
		foreach ($conn->sent as $raw) {
			$f = json_decode($raw, true);
			if (isset($f['op']) && $f['op'] === 'pty.close') {
				$close = $f;
			}
		}
		$this->assertNotNull($close, 'agent must emit a self pty.close on child EOF');
		$this->assertSame('eofclose', $close['data']['pty_id']);
		// `code`, when present, must be an int - never a null value (array_filter drops null)
		if (array_key_exists('code', $close['data'])) {
			$this->assertIsInt($close['data']['code']);
			$this->assertNotNull($close['data']['code']);
		}
		// EOF reap must have removed the session from the pool.
		$this->assertNull($this->agent->ptys->get('eofclose'), 'EOF must reap the session');
	}

	// ---------------------------------------------------------------- pty.data

	public function testDataWritesDecodedBytesToPtyAndSendsNoReply(): void
	{
		// open in raw mode so we can read back exactly what we wrote.
		(new PtyOpenHandler())->handle($this->agent, new FakeConnection(), $this->req('pty.open', ['pty_id' => 'd1', 'command' => 'stty raw -echo; cat']));
		usleep(200000); // let stty raw settle

		$conn = new FakeConnection();
		$payload = "ping-\x00\x01\xff";
		(new PtyDataHandler())->handle($this->agent, $conn, $this->req('pty.data', ['pty_id' => 'd1', 'data' => base64_encode($payload)]));
		$this->assertSame([], $conn->sent, 'pty.data must not send any reply');

		// read it back off the slave to prove the bytes were written.
		$s = $this->agent->ptys->get('d1');
		$got = '';
		$deadline = microtime(true) + 2.0;
		while (microtime(true) < $deadline && strpos($got, "\xff") === false) {
			$chunk = @fread($s->readStream(), 65536);
			if ($chunk !== false && $chunk !== '') {
				$got .= $chunk;
			} else {
				usleep(20000);
			}
		}
		$this->assertStringContainsString($payload, $got, 'decoded bytes must reach the pty (binary-safe)');
	}

	public function testDataUnknownPtyIdIsSilentNoOp(): void
	{
		$conn = new FakeConnection();
		(new PtyDataHandler())->handle($this->agent, $conn, $this->req('pty.data', ['pty_id' => 'nope', 'data' => base64_encode('x')]));
		$this->assertSame([], $conn->sent, 'unknown pty_id -> silent no-op, no reply');
	}

	public function testDataMissingDataFieldIsSilentNoOp(): void
	{
		(new PtyOpenHandler())->handle($this->agent, new FakeConnection(), $this->req('pty.open', ['pty_id' => 'dm', 'command' => 'cat']));
		$conn = new FakeConnection();
		(new PtyDataHandler())->handle($this->agent, $conn, $this->req('pty.data', ['pty_id' => 'dm']));
		$this->assertSame([], $conn->sent, 'missing data -> silent no-op');
	}

	public function testDataNonStringPtyIdIsSilentNoOp(): void
	{
		$conn = new FakeConnection();
		(new PtyDataHandler())->handle($this->agent, $conn, $this->req('pty.data', ['pty_id' => ['not', 'a', 'string'], 'data' => base64_encode('x')]));
		$this->assertSame([], $conn->sent);
	}

	public function testDataInvalidBase64IsSilentNoOpNoCrash(): void
	{
		(new PtyOpenHandler())->handle($this->agent, new FakeConnection(), $this->req('pty.open', ['pty_id' => 'db', 'command' => 'cat']));
		$conn = new FakeConnection();
		// strict base64_decode returns false for this -> handler must bail silently.
		(new PtyDataHandler())->handle($this->agent, $conn, $this->req('pty.data', ['pty_id' => 'db', 'data' => '!!!not-base64!!!']));
		$this->assertSame([], $conn->sent, 'invalid base64 -> silent drop, no fatal');
		$this->assertTrue($this->agent->ptys->get('db')->isRunning(), 'session unharmed by bad base64');
	}

	// -------------------------------------------------------------- pty.resize

	public function testResizeAppliesAndSendsNoReply(): void
	{
		(new PtyOpenHandler())->handle($this->agent, new FakeConnection(), $this->req('pty.open', ['pty_id' => 'r1', 'command' => 'cat', 'cols' => 40, 'rows' => 10]));
		$s = $this->agent->ptys->get('r1');

		$conn = new FakeConnection();
		(new PtyResizeHandler())->handle($this->agent, $conn, $this->req('pty.resize', ['pty_id' => 'r1', 'cols' => 120, 'rows' => 48]));
		$this->assertSame([], $conn->sent, 'pty.resize must not send any reply');

		// prove the ioctl really landed by reading the slave geometry.
		$after = trim((string)shell_exec('stty -F '.escapeshellarg($s->slavePath).' size 2>&1'));
		$this->assertSame('48 120', $after, 'resize handler must apply real geometry (rows cols)');
	}

	public function testResizeUnknownPtyIdIsSilentNoOp(): void
	{
		$conn = new FakeConnection();
		(new PtyResizeHandler())->handle($this->agent, $conn, $this->req('pty.resize', ['pty_id' => 'ghost', 'cols' => 80, 'rows' => 24]));
		$this->assertSame([], $conn->sent);
	}

	public function testResizeMissingDimensionsIsSilentNoOp(): void
	{
		(new PtyOpenHandler())->handle($this->agent, new FakeConnection(), $this->req('pty.open', ['pty_id' => 'rmiss', 'command' => 'cat']));
		$conn = new FakeConnection();
		(new PtyResizeHandler())->handle($this->agent, $conn, $this->req('pty.resize', ['pty_id' => 'rmiss', 'cols' => 80]));
		$this->assertSame([], $conn->sent, 'missing rows -> silent no-op');
	}

	public function testResizeNonNumericDimensionsIsSilentNoOp(): void
	{
		(new PtyOpenHandler())->handle($this->agent, new FakeConnection(), $this->req('pty.open', ['pty_id' => 'rnn', 'command' => 'cat']));
		$conn = new FakeConnection();
		(new PtyResizeHandler())->handle($this->agent, $conn, $this->req('pty.resize', ['pty_id' => 'rnn', 'cols' => 'wide', 'rows' => 'tall']));
		$this->assertSame([], $conn->sent, 'non-numeric dims -> silent no-op');
	}

	// --------------------------------------------------------------- pty.close

	public function testCloseTerminatesChildDropsFromPoolAndSendsNoReply(): void
	{
		(new PtyOpenHandler())->handle($this->agent, new FakeConnection(), $this->req('pty.open', ['pty_id' => 'c1', 'command' => 'cat']));
		$pid = $this->agent->ptys->get('c1')->pid;
		$this->assertTrue(posix_kill($pid, 0));

		$conn = new FakeConnection();
		(new PtyCloseHandler())->handle($this->agent, $conn, $this->req('pty.close', ['pty_id' => 'c1']));
		$this->assertSame([], $conn->sent, 'pty.close must not send any reply');
		$this->assertNull($this->agent->ptys->get('c1'), 'session must be dropped from the pool');

		$gone = false;
		for ($i = 0; $i < 100; $i++) {
			if (!posix_kill($pid, 0)) {
				$gone = true;
				break;
			}
			usleep(20000);
		}
		$this->assertTrue($gone, 'close handler must terminate the child at OS level');
	}

	public function testCloseUnknownPtyIdIsSilentNoOp(): void
	{
		$conn = new FakeConnection();
		(new PtyCloseHandler())->handle($this->agent, $conn, $this->req('pty.close', ['pty_id' => 'ghost']));
		$this->assertSame([], $conn->sent, 'unknown pty_id -> silent no-op, no crash');
	}

	public function testCloseMissingPtyIdIsSilentNoOp(): void
	{
		$conn = new FakeConnection();
		(new PtyCloseHandler())->handle($this->agent, $conn, $this->req('pty.close', []));
		$this->assertSame([], $conn->sent);
	}
}
