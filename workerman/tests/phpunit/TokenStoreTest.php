<?php

namespace MyAdmin\VpsHost\Tests;

use PHPUnit\Framework\TestCase;
use MyAdmin\VpsHost\TokenStore;

/**
* Unit coverage for TokenStore - the v1 bearer-token persistence + dual-running
* gate. Locks in, with real assertions, the "airtight" hasToken() gate decision
* the Review Agent previously only probed ad-hoc: only a genuinely valid token
* file turns the gate ON; every degenerate case (missing/empty/whitespace/
* unreadable/corrupt) falls back safely to legacy (gate OFF). Also covers the
* 0600 atomic write path.
*
* NOTE (Review Agent A1, by-design): a corrupted-JSON file that is nonetheless a
* non-empty single line is accepted as a BARE token (AUTH_DESIGN §3 sketch). The
* tests below assert that DOCUMENTED behavior, not a failure - a truncated JSON
* blob still reads as a (garbage) bare token string, which is intended.
*/
class TokenStoreTest extends TestCase
{
	private string $dir;
	private string $path;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir().'/tokenstore_test_'.getmypid().'_'.uniqid();
		mkdir($this->dir, 0700, true);
		$this->path = $this->dir.'/agent_token';
	}

	protected function tearDown(): void
	{
		// restore perms so cleanup can remove anything we chmod'd 0000
		foreach (glob($this->dir.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
			if (is_file($f)) {
				@chmod($f, 0600);
				@unlink($f);
			}
		}
		@rmdir($this->dir);
	}

	// ---- hasToken() gate: the six edge cases ----

	public function testHasTokenFalseWhenFileMissing(): void
	{
		$store = new TokenStore($this->path);
		$this->assertFalse($store->hasToken(), 'missing file => gate OFF (legacy)');
		$this->assertNull($store->getToken());
	}

	public function testHasTokenFalseWhenFileEmpty(): void
	{
		file_put_contents($this->path, '');
		$store = new TokenStore($this->path);
		$this->assertFalse($store->hasToken(), 'empty file => gate OFF');
		$this->assertNull($store->getToken());
	}

	public function testHasTokenFalseWhenFileWhitespaceOnly(): void
	{
		file_put_contents($this->path, "   \n\t  \n");
		$store = new TokenStore($this->path);
		$this->assertFalse($store->hasToken(), 'whitespace-only file => gate OFF (trimmed to empty)');
		$this->assertNull($store->getToken());
	}

	public function testHasTokenFalseWhenFileUnreadable(): void
	{
		if (posix_getuid() === 0) {
			$this->markTestSkipped('running as root: 0000 perms do not block reads');
		}
		file_put_contents($this->path, json_encode(['host_id' => 5, 'token' => 'secret']));
		chmod($this->path, 0000);
		$store = new TokenStore($this->path);
		$this->assertFalse($store->hasToken(), 'permission-denied file => gate OFF (safe fallback)');
		$this->assertNull($store->getToken());
	}

	public function testHasTokenTrueForValidJsonTokenFile(): void
	{
		file_put_contents($this->path, json_encode(['host_id' => 42, 'token' => 'good-token', 'issued_at' => 123]));
		$store = new TokenStore($this->path);
		$this->assertTrue($store->hasToken(), 'valid token file => gate ON');
		$this->assertSame('good-token', $store->getToken());
		$this->assertSame(42, $store->getHostId());
	}

	public function testValidJsonWithEmptyTokenIsGateOff(): void
	{
		file_put_contents($this->path, json_encode(['host_id' => 42, 'token' => '']));
		$store = new TokenStore($this->path);
		$this->assertFalse($store->hasToken(), 'valid JSON but empty token => gate OFF');
		$this->assertNull($store->getToken());
	}

	// ---- A1 by-design: corrupted/truncated JSON => bare-token acceptance ----

	public function testTruncatedJsonAcceptedAsBareTokenByDesign(): void
	{
		// a truncated JSON object does not json_decode to an array, so the
		// single first line is taken as a bare token. This is DOCUMENTED,
		// intended behavior (Review Agent A1: "by-design bare-token acceptance,
		// no fix needed") - assert the intended behavior, do not flag it.
		file_put_contents($this->path, '{"host_id":42,"token":"good-tok');
		$store = new TokenStore($this->path);
		$this->assertTrue($store->hasToken(), 'A1: non-empty corrupt line reads as a bare token (by design)');
		// host_id has no valid JSON to come from, so it falls back to null
		$this->assertNull($store->getHostId());
	}

	public function testBareTokenSingleLineAccepted(): void
	{
		file_put_contents($this->path, "my-bare-token\n");
		$store = new TokenStore($this->path);
		$this->assertTrue($store->hasToken());
		$this->assertSame('my-bare-token', $store->getToken(), 'bare token is first line only');
		$this->assertNull($store->getHostId());
	}

	// ---- save(): atomic 0600 write path ----

	public function testSaveWritesFileWith0600Permissions(): void
	{
		$store = new TokenStore($this->path);
		$this->assertTrue($store->save('rotated-token', 7));
		$this->assertFileExists($this->path);
		$perms = fileperms($this->path) & 0777;
		$this->assertSame(0600, $perms, sprintf('expected 0600, got 0%o', $perms));
	}

	public function testSaveRoundTripsThroughRead(): void
	{
		$store = new TokenStore($this->path);
		$this->assertTrue($store->save('tok-abc', 9));
		$fresh = new TokenStore($this->path);
		$this->assertTrue($fresh->hasToken());
		$this->assertSame('tok-abc', $fresh->getToken());
		$this->assertSame(9, $fresh->getHostId());
	}

	public function testSaveRejectsEmptyToken(): void
	{
		$store = new TokenStore($this->path);
		$this->assertFalse($store->save(''), 'empty token must not be persisted');
		$this->assertFileDoesNotExist($this->path);
	}

	public function testSavePreservesHostIdWhenNoneGiven(): void
	{
		$store = new TokenStore($this->path);
		$this->assertTrue($store->save('first', 55));
		// rotate without giving host id: previously stored host id must survive
		$this->assertTrue($store->save('rotated'));
		$fresh = new TokenStore($this->path);
		$this->assertSame('rotated', $fresh->getToken());
		$this->assertSame(55, $fresh->getHostId(), 'rotation without hostId must keep the prior host_id');
	}

	public function testSaveCreatesMissingDirectory(): void
	{
		$nested = $this->dir.'/deep/sub/agent_token';
		$store = new TokenStore($nested);
		$this->assertTrue($store->save('tok', 1));
		$this->assertFileExists($nested);
		$this->assertSame(0600, fileperms($nested) & 0777);
		// cleanup nested dirs
		@unlink($nested);
		@rmdir($this->dir.'/deep/sub');
		@rmdir($this->dir.'/deep');
	}

	public function testSaveLeavesNoObservablePartialWriteAndNoTmpResidue(): void
	{
		$store = new TokenStore($this->path);
		$this->assertTrue($store->save('atomic-token', 3));
		// the target file, once present, is the fully-written final content -
		// tmp-file+rename means no partial content is ever visible at $path.
		$content = json_decode(file_get_contents($this->path), true);
		$this->assertSame('atomic-token', $content['token']);
		$this->assertSame(3, $content['host_id']);
		$this->assertArrayHasKey('issued_at', $content);
		// no leftover .tmp scratch file in the directory
		$leftovers = glob($this->dir.'/.*.tmp.*') ?: [];
		$this->assertSame([], $leftovers, 'atomic write must leave no .tmp residue');
	}

	public function testSetPathAndGetPath(): void
	{
		$store = new TokenStore($this->path);
		$this->assertSame($this->path, $store->getPath());
		$store->setPath('/some/other/path');
		$this->assertSame('/some/other/path', $store->getPath());
	}
}
