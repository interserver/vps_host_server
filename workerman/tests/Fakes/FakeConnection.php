<?php

namespace MyAdmin\VpsHost\Tests\Fakes;

use Workerman\Connection\AsyncTcpConnection;

/**
* In-memory stand-in for a websocket connection. IS-A AsyncTcpConnection so it
* satisfies the handler/Agent type hints, but bypasses the real constructor
* (no address parsing, no socket) and captures every send() for assertions.
*/
class FakeConnection extends AsyncTcpConnection
{
	/** @var array<int, string> raw frames passed to send() */
	public array $sent = [];
	public bool $closed = false;

	// deliberately DO NOT call parent::__construct - we want no real socket.
	public function __construct()
	{
	}

	public function send(mixed $sendBuffer, bool $raw = false): bool|null
	{
		$this->sent[] = $sendBuffer;
		return true;
	}

	public function close(mixed $data = null, bool $raw = false): void
	{
		$this->closed = true;
	}

	/** convenience: last frame decoded as an assoc array */
	public function lastDecoded(): ?array
	{
		if (empty($this->sent)) {
			return null;
		}
		return json_decode($this->sent[count($this->sent) - 1], true);
	}
}
