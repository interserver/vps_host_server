<?php

namespace MyAdmin\VpsHost\Tests;

use PHPUnit\Framework\TestCase;
use Workerman\Worker;

/**
* Base test case that redirects Worker::safeEcho output into an in-memory stream
* (Worker::$outputStream) so tests can (a) assert on emitted log lines and
* (b) not leak to the real STDOUT or trip PHPUnit's risky-output-buffer /
* error-handler guards. safeEcho itself balances its own set/restore_error_handler.
*/
abstract class AgentTestCase extends TestCase
{
	/** @var resource */
	private $outStream;
	/** @var mixed previous Worker::$outputStream */
	private $prevStream;

	protected function setUp(): void
	{
		$this->outStream = fopen('php://memory', 'r+');
		$this->prevStream = Worker::$outputStream;
		Worker::$outputStream = $this->outStream;
	}

	protected function tearDown(): void
	{
		Worker::$outputStream = $this->prevStream;
		if (is_resource($this->outStream)) {
			fclose($this->outStream);
		}
	}

	/** everything written via Worker::safeEcho since setUp */
	protected function capturedOutput(): string
	{
		rewind($this->outStream);
		return stream_get_contents($this->outStream);
	}
}
