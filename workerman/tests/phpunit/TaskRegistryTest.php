<?php

namespace MyAdmin\VpsHost\Tests;

use PHPUnit\Framework\TestCase;
use MyAdmin\VpsHost\TaskRegistry;

/**
* TaskRegistry replaces the old second stdObject used by the Task worker. It must:
*  - load *.php files from a dir, keyed by basename, each returning a closure
*  - prepend itself as the closure's first arg (old stdObject::__call convention)
*  - throw on an unknown task name
*/
class TaskRegistryTest extends TestCase
{
	private string $dir;

	protected function setUp(): void
	{
		$this->dir = sys_get_temp_dir().'/taskreg_'.uniqid();
		mkdir($this->dir);
		// task echoing back the registry it was handed + its args
		file_put_contents($this->dir.'/alpha.php', <<<'PHP'
<?php
return function ($tasks, ...$args) {
	return ['registry_class' => get_class($tasks), 'args' => $args];
};
PHP);
		// task that calls another task through the registry (proves inter-task calls)
		file_put_contents($this->dir.'/beta.php', <<<'PHP'
<?php
return function ($tasks, $x) {
	$inner = $tasks->call('alpha', $x, 'extra');
	return ['delegated' => $inner];
};
PHP);
	}

	protected function tearDown(): void
	{
		array_map('unlink', glob($this->dir.'/*.php'));
		rmdir($this->dir);
	}

	public function testLoadRegistersByBasename(): void
	{
		$reg = new TaskRegistry();
		$reg->load($this->dir);
		$this->assertTrue($reg->has('alpha'));
		$this->assertTrue($reg->has('beta'));
		$this->assertFalse($reg->has('nonexistent'));
	}

	public function testCallPrependsRegistryAsFirstArg(): void
	{
		$reg = new TaskRegistry();
		$reg->load($this->dir);
		$result = $reg->call('alpha', 'one', 'two');
		$this->assertSame(TaskRegistry::class, $result['registry_class']);
		$this->assertSame(['one', 'two'], $result['args']);
	}

	public function testCallWithNoArgs(): void
	{
		$reg = new TaskRegistry();
		$reg->load($this->dir);
		$result = $reg->call('alpha');
		$this->assertSame([], $result['args']);
	}

	public function testInterTaskCallThroughRegistry(): void
	{
		$reg = new TaskRegistry();
		$reg->load($this->dir);
		$result = $reg->call('beta', 'payload');
		$this->assertSame(['payload', 'extra'], $result['delegated']['args']);
	}

	public function testUnknownTaskThrows(): void
	{
		$reg = new TaskRegistry();
		$reg->load($this->dir);
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Call to undefined task TaskRegistry::ghost()');
		$reg->call('ghost');
	}

	public function testLoadsRealSrcTasksDirectory(): void
	{
		// smoke-test against the actual src/Tasks (each file must return a callable)
		$reg = new TaskRegistry();
		$reg->load(__DIR__.'/../../src/Tasks');
		foreach (['run', 'vps_get_cpu', 'vps_get_list', 'vps_queue', 'vps_update_info', 'xml2array'] as $t) {
			$this->assertTrue($reg->has($t), "src/Tasks/{$t}.php did not register a callable");
		}
	}
}
