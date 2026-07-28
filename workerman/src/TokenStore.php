<?php

namespace MyAdmin\VpsHost;

/**
* TokenStore - local persistence of the v1 bearer token (AUTH_DESIGN.md §3).
*
* The presence of a non-empty token file is ALSO the dual-running gate for the
* whole agent-side v1 protocol stack: no token file = the agent behaves exactly
* as the legacy-only agent did (legacy {type:"login"} on connect, legacy frame
* shapes everywhere). This mirrors the hub's Flag-A-dormant philosophy without
* needing the hub's GlobalData flag mechanism.
*
* File format: a JSON object {"host_id":int,"token":str,"issued_at":ts} written
* mode 0600 (default path /etc/datacentered/agent_token, overridable via
* config.ini [v1] token_file - used by tests/harnesses). A bare-token file (a
* single line containing only the token, as AUTH_DESIGN §3 sketches) is also
* accepted on read; host_id then falls back to config [v1] host_id.
*
* The plaintext token only ever exists in hub memory, one WSS frame, and this
* 0600 file - it must never be logged (Agent::onMessage redacts inbound frames
* containing a "token" field before echoing them).
*/
class TokenStore
{
	public const DEFAULT_PATH = '/etc/datacentered/agent_token';

	private string $path;

	public function __construct(string $path = self::DEFAULT_PATH)
	{
		$this->path = $path;
	}

	public function setPath(string $path): void
	{
		$this->path = $path;
	}

	public function getPath(): string
	{
		return $this->path;
	}

	/**
	* the v1 dual-running gate: do we hold a usable token?
	*/
	public function hasToken(): bool
	{
		$data = $this->read();
		return $data !== null && $data['token'] !== '';
	}

	public function getToken(): ?string
	{
		$data = $this->read();
		return $data !== null && $data['token'] !== '' ? $data['token'] : null;
	}

	public function getHostId(): ?int
	{
		$data = $this->read();
		return $data !== null && $data['host_id'] !== null ? (int)$data['host_id'] : null;
	}

	/**
	* persist a (possibly rotated) token, mode 0600, atomically (tmp file in the
	* same dir + rename). Keeps any previously stored host_id when none is given.
	*
	* @return bool true on success
	*/
	public function save(string $token, ?int $hostId = null): bool
	{
		if ($token === '') {
			return false;
		}
		if ($hostId === null) {
			$hostId = $this->getHostId();
		}
		$dir = dirname($this->path);
		if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
			return false;
		}
		$payload = json_encode(['host_id' => $hostId, 'token' => $token, 'issued_at' => time()]);
		$tmp = $dir.'/.'.basename($this->path).'.tmp.'.getmypid();
		if (@file_put_contents($tmp, $payload) === false) {
			return false;
		}
		if (!@chmod($tmp, 0600) || !@rename($tmp, $this->path)) {
			@unlink($tmp);
			return false;
		}
		return true;
	}

	/**
	* @return array{host_id: int|null, token: string}|null null when no readable file
	*/
	private function read(): ?array
	{
		if (!is_file($this->path) || !is_readable($this->path)) {
			return null;
		}
		$raw = trim((string)@file_get_contents($this->path));
		if ($raw === '') {
			return null;
		}
		$json = json_decode($raw, true);
		if (is_array($json) && isset($json['token']) && is_string($json['token'])) {
			return [
				'host_id' => isset($json['host_id']) && is_numeric($json['host_id']) ? (int)$json['host_id'] : null,
				'token' => $json['token']
			];
		}
		// bare-token file (single line, AUTH_DESIGN §3 sketch)
		return ['host_id' => null, 'token' => strtok($raw, "\n")];
	}
}
