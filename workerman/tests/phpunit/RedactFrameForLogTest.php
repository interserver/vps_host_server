<?php

namespace MyAdmin\VpsHost\Tests;

use PHPUnit\Framework\TestCase;
use MyAdmin\VpsHost\Agent;
use MyAdmin\VpsHost\V1Envelope;

/**
* Regression lock for the redaction-hardening fix (most recent Fix Agent round).
* Agent::redactFrameForLog() must never let bearer-token material reach the log:
*   - a gzip-encoded config.token frame => the (trivially reversible) base64 data
*     blob is replaced wholesale with a redaction placeholder;
*   - a plain config.token frame => same;
*   - a plaintext "token" field in any frame => value-level redaction;
*   - a gzip config.maps (or any non-token) frame => NOT over-redacted, logs
*     normally.
* This test exists so the behavior can never silently regress in a future step.
*
* redactFrameForLog() is private static; it is exercised here via reflection so
* the redaction logic itself is asserted directly (independent of the full
* onMessage wiring).
*/
class RedactFrameForLogTest extends TestCase
{
	private function redact($raw): string
	{
		$m = new \ReflectionMethod(Agent::class, 'redactFrameForLog');
		$m->setAccessible(true);
		return $m->invoke(null, $raw);
	}

	public function testGzipConfigTokenFrameRedactsAndLeaksNoBlob(): void
	{
		$secret = 'super-secret-bearer-token-value-1234567890';
		$env = V1Envelope::request('config.token', ['token' => $secret, 'host_id' => 7], true);
		$this->assertSame('gzip', $env['enc']);
		$rawBlob = $env['data']; // the base64(gzcompress(json)) blob
		$raw = json_encode($env);

		$out = $this->redact($raw);

		$this->assertStringContainsString('[REDACTED]', $out, 'gzip config.token must be redacted');
		$this->assertStringNotContainsString($secret, $out, 'raw token must not appear');
		$this->assertStringNotContainsString($rawBlob, $out, 'the base64 blob (reversible) must not appear');
		// and the blob must not be recoverable: assert no substring of it survived
		$decoded = json_decode($out, true);
		$this->assertSame('[REDACTED]', $decoded['data']);
		$this->assertSame('config.token', $decoded['op'], 'other fields still logged for context');
	}

	public function testPlainConfigTokenFrameRedacts(): void
	{
		$secret = 'plain-token-abcdef';
		$env = V1Envelope::request('config.token', ['token' => $secret, 'host_id' => 3], false);
		$raw = json_encode($env);

		$out = $this->redact($raw);

		$this->assertStringNotContainsString($secret, $out, 'plain config.token token must not appear');
		$decoded = json_decode($out, true);
		// whole data field is replaced for config.token op regardless of encoding
		$this->assertSame('[REDACTED]', $decoded['data']);
	}

	public function testPlaintextTokenFieldInAnyFrameIsValueRedacted(): void
	{
		// a non-config.token frame that still carries a "token" field
		$raw = json_encode(['type' => 'login', 'name' => 'host1', 'token' => 'leak-me-not']);
		$out = $this->redact($raw);
		$this->assertStringNotContainsString('leak-me-not', $out);
		$this->assertStringContainsString('"token":"[REDACTED]"', $out);
		// non-sensitive fields survive
		$this->assertStringContainsString('host1', $out);
	}

	public function testGzipConfigMapsFrameIsNotOverRedacted(): void
	{
		$mapData = ['servers' => [1, 2, 3], 'ips' => ['10.0.0.1']];
		$env = V1Envelope::request('config.maps', $mapData, true);
		$raw = json_encode($env);

		$out = $this->redact($raw);

		// config.maps is not a token frame - it must log unchanged (no redaction)
		$this->assertSame($raw, $out, 'non-token gzip frame must not be over-redacted');
		$this->assertStringNotContainsString('[REDACTED]', $out);
	}

	public function testPlainNonTokenFrameLogsUnchanged(): void
	{
		$raw = json_encode(['type' => 'ping']);
		$this->assertSame($raw, $this->redact($raw));
	}

	public function testNonStringInputHandledGracefully(): void
	{
		// var_export fallback path - must not throw
		$out = $this->redact(['not' => 'a string']);
		$this->assertIsString($out);
	}
}
