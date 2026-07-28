<?php

namespace MyAdmin\VpsHost\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use MyAdmin\VpsHost\V1Envelope;

/**
* Unit coverage for V1Envelope - the agent-side PROTOCOL_V1 §1 envelope
* detection/build/parse utility. Closes advisory A3 (no committed unit tests on
* the new v1 infra) for the detection predicates, the request/reply/error
* builders (exact field shapes per §1), enc:"gzip" round-trip + malformed
* failure modes, and RFC-4122 uuid generation.
*/
class V1EnvelopeTest extends TestCase
{
	// ---- valid v1 REQUEST shapes ----

	public static function validRequestProvider(): array
	{
		return [
			'plain data' => [['v' => 1, 'id' => 'abc', 'op' => 'ping', 'ts' => 1720000000, 'data' => []]],
			'data with fields' => [['v' => 1, 'id' => 'x-1', 'op' => 'cmd.exec', 'ts' => 1720000000, 'data' => ['command' => 'echo hi']]],
			'gzip string data' => [['v' => 1, 'id' => 'g', 'op' => 'config.maps', 'ts' => 1720000000, 'enc' => 'gzip', 'data' => 'base64blob']],
		];
	}

	#[DataProvider('validRequestProvider')]
	public function testIsRequestAcceptsValidRequestShapes(array $frame): void
	{
		$this->assertTrue(V1Envelope::isRequest($frame));
		$this->assertTrue(V1Envelope::isV1($frame));
		$this->assertFalse(V1Envelope::isReply($frame), 'a request must not be classed as a reply');
	}

	// ---- valid v1 REPLY shapes (ok:true and ok:false) ----

	public static function validReplyProvider(): array
	{
		return [
			'ok true plain' => [['v' => 1, 're' => 'abc', 'ok' => true, 'data' => []]],
			'ok true with data' => [['v' => 1, 're' => 'abc', 'ok' => true, 'data' => ['result' => 1]]],
			'ok true gzip' => [['v' => 1, 're' => 'abc', 'ok' => true, 'enc' => 'gzip', 'data' => 'blob']],
			'ok false error' => [['v' => 1, 're' => 'abc', 'ok' => false, 'error' => ['code' => 'bad_request', 'message' => 'nope']]],
		];
	}

	#[DataProvider('validReplyProvider')]
	public function testIsReplyAcceptsValidReplyShapes(array $frame): void
	{
		$this->assertTrue(V1Envelope::isReply($frame));
		$this->assertTrue(V1Envelope::isV1($frame));
		$this->assertFalse(V1Envelope::isRequest($frame), 'a reply must not be classed as a request');
	}

	// ---- legacy {type:...} frames must NEVER match as v1 ----

	public static function legacyFrameProvider(): array
	{
		return [
			'login' => [['type' => 'login', 'ima' => 'host', 'name' => 'host1']],
			'ping' => [['type' => 'ping']],
			'run' => [['type' => 'run', 'id' => 'r1', 'command' => 'echo hi']],
			'stop_run' => [['type' => 'stop_run', 'id' => 'r1']],
			'get_map' => [['type' => 'get_map', 'content' => []]],
			'running' => [['type' => 'running', 'id' => 'r1', 'stdin' => "y\n"]],
		];
	}

	#[DataProvider('legacyFrameProvider')]
	public function testLegacyFramesAreNotV1(array $frame): void
	{
		$this->assertFalse(V1Envelope::isRequest($frame), 'legacy frame must not be a v1 request');
		$this->assertFalse(V1Envelope::isReply($frame), 'legacy frame must not be a v1 reply');
		$this->assertFalse(V1Envelope::isV1($frame), 'legacy frame must not be any v1 envelope');
	}

	// ---- malformed / partial-v1-looking shapes must NOT match ----

	public static function malformedProvider(): array
	{
		return [
			'not an array' => ['just a string'],
			'null' => [null],
			'empty array' => [[]],
			'v is string 1' => [['v' => '1', 'id' => 'a', 'op' => 'ping', 'ts' => 1, 'data' => []]],
			'v is 2' => [['v' => 2, 'id' => 'a', 'op' => 'ping', 'ts' => 1, 'data' => []]],
			'missing op' => [['v' => 1, 'id' => 'a', 'ts' => 1, 'data' => []]],
			'empty op' => [['v' => 1, 'id' => 'a', 'op' => '', 'ts' => 1, 'data' => []]],
			'missing id' => [['v' => 1, 'op' => 'ping', 'ts' => 1, 'data' => []]],
			'empty id' => [['v' => 1, 'id' => '', 'op' => 'ping', 'ts' => 1, 'data' => []]],
			'ts is string' => [['v' => 1, 'id' => 'a', 'op' => 'ping', 'ts' => '1', 'data' => []]],
			'missing ts' => [['v' => 1, 'id' => 'a', 'op' => 'ping', 'data' => []]],
			'missing data' => [['v' => 1, 'id' => 'a', 'op' => 'ping', 'ts' => 1]],
			'string data without gzip enc' => [['v' => 1, 'id' => 'a', 'op' => 'ping', 'ts' => 1, 'data' => 'oops']],
			// reply-ish but broken
			're empty' => [['v' => 1, 're' => '', 'ok' => true, 'data' => []]],
			'ok not bool' => [['v' => 1, 're' => 'a', 'ok' => 'true', 'data' => []]],
			'ok true missing data' => [['v' => 1, 're' => 'a', 'ok' => true]],
			'ok false missing error' => [['v' => 1, 're' => 'a', 'ok' => false]],
			'ok false error not array' => [['v' => 1, 're' => 'a', 'ok' => false, 'error' => 'boom']],
		];
	}

	#[DataProvider('malformedProvider')]
	public function testMalformedShapesAreNotV1($frame): void
	{
		$this->assertFalse(V1Envelope::isRequest($frame));
		$this->assertFalse(V1Envelope::isReply($frame));
		$this->assertFalse(V1Envelope::isV1($frame));
	}

	/**
	* Working-as-designed: isRequest() is a structural SHAPE gate, not a decode
	* validator. A frame with array data + a (mismatched) enc:"gzip" marker still
	* satisfies is_array($data['data']) and is therefore a valid v1 request; the
	* mismatch is caught later at decodeData() time, not at detection. Locked in
	* so this deliberate leniency is not mistaken for a bug in a future change.
	*/
	public function testGzipMarkerWithArrayDataStillDetectsAsRequestByDesign(): void
	{
		$frame = ['v' => 1, 'id' => 'a', 'op' => 'ping', 'ts' => 1, 'enc' => 'gzip', 'data' => []];
		$this->assertTrue(V1Envelope::isRequest($frame), 'array data passes the shape gate regardless of enc marker');
		// but decodeData rejects the enc/data-type mismatch downstream
		$this->assertFalse(V1Envelope::decodeData($frame), 'the enc:"gzip"/array mismatch is caught at decode time');
	}

	// ---- REQUEST builder: exact §1 field shape ----

	public function testRequestBuilderFieldShape(): void
	{
		$env = V1Envelope::request('cmd.exec', ['command' => 'ls']);
		$this->assertSame(1, $env['v']);
		$this->assertSame('cmd.exec', $env['op']);
		$this->assertSame(['command' => 'ls'], $env['data']);
		$this->assertIsInt($env['ts']);
		$this->assertIsString($env['id']);
		$this->assertArrayNotHasKey('enc', $env, 'plain request must not carry enc');
		// exact key set per §1: v, id, op, ts, data
		$this->assertSame(['v', 'id', 'op', 'ts', 'data'], array_keys($env));
		// the builder output must itself be detected as a request
		$this->assertTrue(V1Envelope::isRequest($env));
	}

	public function testRequestBuilderGzipShape(): void
	{
		$data = ['command' => 'ls', 'big' => str_repeat('x', 500)];
		$env = V1Envelope::request('cmd.exec', $data, true);
		$this->assertSame('gzip', $env['enc']);
		$this->assertIsString($env['data']);
		$this->assertTrue(V1Envelope::isRequest($env), 'gzip request must still detect');
		// round-trips back to the original array
		$this->assertTrue(V1Envelope::decodeData($env));
		$this->assertSame($data, $env['data']);
	}

	// ---- REPLY builder: exact §1 field shape ----

	public function testReplyBuilderFieldShape(): void
	{
		$env = V1Envelope::reply('req-123', ['result' => 'ok']);
		$this->assertSame(1, $env['v']);
		$this->assertSame('req-123', $env['re']);
		$this->assertTrue($env['ok']);
		$this->assertSame(['result' => 'ok'], $env['data']);
		$this->assertArrayNotHasKey('op', $env, 'replies carry no op (§1)');
		$this->assertSame(['v', 're', 'ok', 'data'], array_keys($env));
		$this->assertTrue(V1Envelope::isReply($env));
	}

	public function testReplyBuilderGzipShape(): void
	{
		$data = ['payload' => str_repeat('z', 400)];
		$env = V1Envelope::reply('req-9', $data, true);
		$this->assertSame('gzip', $env['enc']);
		$this->assertIsString($env['data']);
		$this->assertTrue(V1Envelope::isReply($env));
		$this->assertTrue(V1Envelope::decodeData($env));
		$this->assertSame($data, $env['data']);
	}

	// ---- ERROR builder: exact §1 field shape ----

	public function testErrorBuilderFieldShape(): void
	{
		$env = V1Envelope::error('req-7', 'unknown_op', 'no handler for op foo');
		$this->assertSame(1, $env['v']);
		$this->assertSame('req-7', $env['re']);
		$this->assertFalse($env['ok']);
		$this->assertSame(['code' => 'unknown_op', 'message' => 'no handler for op foo'], $env['error']);
		$this->assertArrayNotHasKey('data', $env);
		$this->assertSame(['v', 're', 'ok', 'error'], array_keys($env));
		// an error reply must be detected as a reply
		$this->assertTrue(V1Envelope::isReply($env));
		$this->assertTrue(V1Envelope::isV1($env));
	}

	// ---- enc:"gzip" decode round-trip + malformed failure modes ----

	public function testDecodeDataPlainEnvelopePassesThrough(): void
	{
		$env = ['v' => 1, 'id' => 'a', 'op' => 'ping', 'ts' => 1, 'data' => ['x' => 1]];
		$this->assertTrue(V1Envelope::decodeData($env));
		$this->assertSame(['x' => 1], $env['data']);
	}

	public function testDecodeDataPlainEnvelopeWithNonArrayDataFails(): void
	{
		$env = ['v' => 1, 'id' => 'a', 'op' => 'ping', 'ts' => 1, 'data' => 'notarray'];
		$this->assertFalse(V1Envelope::decodeData($env));
	}

	public function testDecodeDataGzipRoundTrip(): void
	{
		$original = ['servers' => [1, 2, 3], 'nested' => ['a' => 'b']];
		$env = [
			'v' => 1, 'id' => 'a', 'op' => 'config.maps', 'ts' => 1,
			'enc' => 'gzip',
			'data' => base64_encode(gzcompress(json_encode($original), 9))
		];
		$this->assertTrue(V1Envelope::decodeData($env));
		$this->assertSame($original, $env['data']);
		$this->assertArrayNotHasKey('enc', $env, 'enc marker must be cleared after decode');
	}

	public function testDecodeDataFailsOnBadBase64(): void
	{
		// strict base64_decode rejects chars outside the alphabet
		$env = ['enc' => 'gzip', 'data' => '@@@not base64@@@'];
		$this->assertFalse(V1Envelope::decodeData($env), 'bad base64 must fail gracefully');
	}

	public function testDecodeDataFailsOnBadZlibStream(): void
	{
		// valid base64 but not a zlib stream
		$env = ['enc' => 'gzip', 'data' => base64_encode('this is not compressed')];
		$this->assertFalse(V1Envelope::decodeData($env), 'bad zlib stream must fail gracefully (no fatal)');
	}

	public function testDecodeDataFailsOnNonJsonAfterInflate(): void
	{
		// valid base64, valid zlib, but the inflated bytes are not JSON
		$env = ['enc' => 'gzip', 'data' => base64_encode(gzcompress('not json at all', 9))];
		$this->assertFalse(V1Envelope::decodeData($env), 'non-JSON after inflate must fail gracefully');
	}

	public function testDecodeDataFailsOnJsonScalarAfterInflate(): void
	{
		// inflates to valid JSON but a scalar, not an array/object
		$env = ['enc' => 'gzip', 'data' => base64_encode(gzcompress(json_encode(42), 9))];
		$this->assertFalse(V1Envelope::decodeData($env), 'JSON scalar (non-array) after inflate must fail');
	}

	public function testDecodeDataFailsOnUnknownEncoding(): void
	{
		$env = ['enc' => 'deflate', 'data' => 'whatever'];
		$this->assertFalse(V1Envelope::decodeData($env), 'unknown enc value must fail (§1: only "gzip")');
	}

	public function testDecodeDataFailsWhenGzipEncButDataNotString(): void
	{
		$env = ['enc' => 'gzip', 'data' => ['already' => 'array']];
		$this->assertFalse(V1Envelope::decodeData($env));
	}

	// ---- RFC-4122 v4 uuid ----

	public function testUuidFormatValidity(): void
	{
		for ($i = 0; $i < 200; $i++) {
			$uuid = V1Envelope::uuid();
			$this->assertMatchesRegularExpression(
				'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
				$uuid,
				"uuid '{$uuid}' is not a valid RFC-4122 v4 string"
			);
		}
	}

	public function testUuidUniquenessAcrossManyCalls(): void
	{
		$seen = [];
		for ($i = 0; $i < 5000; $i++) {
			$seen[V1Envelope::uuid()] = true;
		}
		$this->assertCount(5000, $seen, 'uuid() produced a collision across 5000 calls');
	}

	public function testRequestIdsAreDistinctPerCall(): void
	{
		$a = V1Envelope::request('ping');
		$b = V1Envelope::request('ping');
		$this->assertNotSame($a['id'], $b['id']);
	}
}
