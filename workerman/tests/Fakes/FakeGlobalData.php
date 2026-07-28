<?php

namespace MyAdmin\VpsHost\Tests\Fakes;

/**
* Minimal in-memory stand-in for \GlobalData\Client - just a magic property bag
* so code doing `global $global; $global->lastMessageTime = time();` works
* without a running GlobalData server.
*/
class FakeGlobalData
{
	private array $store = [];

	public function __get($name)
	{
		return $this->store[$name] ?? null;
	}

	public function __set($name, $value)
	{
		$this->store[$name] = $value;
	}

	public function __isset($name)
	{
		return isset($this->store[$name]);
	}

	public function cas($key, $old, $new): bool
	{
		if (($this->store[$key] ?? null) === $old) {
			$this->store[$key] = $new;
			return true;
		}
		return false;
	}
}
