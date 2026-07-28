<?php

namespace MyAdmin\VpsHost;

/**
* TaskRegistry - explicit name => closure registry for the TaskWorker functions
* loaded from src/Tasks/*.php.
*
* Replaces the old stdObject dynamic-property/__call dispatch. Each task file still
* returns a closure whose first argument is this registry (so tasks can invoke other
* tasks via $tasks->call('xml2array', ...)).
*/
class TaskRegistry
{
	/**
	* @var array<string, callable>
	*/
	private array $tasks = [];

	public function load(string $dir): void
	{
		foreach (glob(rtrim($dir, '/').'/*.php') as $function_file) {
			$function = basename($function_file, '.php');
			$this->tasks[$function] = include $function_file;
		}
	}

	public function has(string $type): bool
	{
		return isset($this->tasks[$type]) && is_callable($this->tasks[$type]);
	}

	/**
	* Invoke a task closure with this registry prepended as its first argument
	* (matching the old stdObject::__call calling convention).
	*/
	public function call(string $type, ...$args)
	{
		if (!$this->has($type)) {
			throw new \Exception("Fatal error: Call to undefined task TaskRegistry::{$type}()");
		}
		return \call_user_func($this->tasks[$type], $this, ...$args);
	}
}
