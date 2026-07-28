<?php

declare(strict_types=1);

use MyAdmin\VpsHost\ReactLoopBridge;
use MyAdmin\VpsHost\ReactLoopBridgeTimer;
use PHPUnit\Framework\TestCase;
use Workerman\Events\Select;

/**
* Covers the Workerman-v5 <-> React integration point that the rest of the suite
* cannot reach through mocks: Worker::getEventLoop() returns a Workerman
* EventInterface which React\ChildProcess\Process::start() rejects with
* InvalidArgumentException. ReactLoopBridge adapts Workerman's live loop to
* React's LoopInterface so RunHandler / Tasks/vps_queue.php work at runtime.
*
* Uses a real Workerman\Events\Select driver (the same class a production
* worker gets without ext-event/ev) driven directly, and a real child process.
*/
class ReactLoopBridgeTest extends TestCase
{
	public function testWorkermanLoopIsRejectedByReactProcessStart(): void
	{
		// documents the underlying incompatibility the bridge exists for
		$this->assertNotInstanceOf(\React\EventLoop\LoopInterface::class, new Select());
		$process = new \React\ChildProcess\Process('echo nope');
		$this->expectException(\InvalidArgumentException::class);
		$process->start(new Select());
	}

	public function testChildProcessRunsOnBridgedWorkermanSelectLoop(): void
	{
		$event = new Select();
		$loop = new ReactLoopBridge($event);
		$this->assertInstanceOf(\React\EventLoop\LoopInterface::class, $loop);

		$stdout = '';
		$stderr = '';
		$exit = null;
		$process = new \React\ChildProcess\Process('echo out-ok; echo err-ok 1>&2; exit 5');
		$process->start($loop);
		$process->stdout->on('data', function ($d) use (&$stdout) { $stdout .= $d; });
		$process->stderr->on('data', function ($d) use (&$stderr) { $stderr .= $d; });
		$process->on('exit', function ($code) use (&$exit, $event) {
			$exit = $code;
			$event->stop();
		});
		// failsafe so a regression cannot hang the suite
		$event->delay(10.0, function () use ($event) { $event->stop(); });
		$event->run();

		$this->assertSame('out-ok', trim($stdout));
		$this->assertSame('err-ok', trim($stderr));
		$this->assertSame(5, $exit);
	}

	public function testTimersDelegateAndCancel(): void
	{
		$event = new Select();
		$loop = new ReactLoopBridge($event);

		$ticks = 0;
		$oneShot = 0;
		$cancelledFired = 0;
		$periodic = $loop->addPeriodicTimer(0.05, function ($timer) use (&$ticks, $loop, $event) {
			if (++$ticks >= 3) {
				$loop->cancelTimer($timer); // React-style cancel-from-inside-callback
				$event->stop();
			}
		});
		$this->assertInstanceOf(ReactLoopBridgeTimer::class, $periodic);
		$this->assertTrue($periodic->isPeriodic());

		$loop->addTimer(0.05, function () use (&$oneShot) { $oneShot++; });
		$victim = $loop->addTimer(0.05, function () use (&$cancelledFired) { $cancelledFired++; });
		$loop->cancelTimer($victim);
		$this->assertNull($victim->workermanTimerId);

		$event->delay(10.0, function () use ($event) { $event->stop(); });
		$event->run();

		$this->assertSame(3, $ticks);
		$this->assertSame(1, $oneShot);
		$this->assertSame(0, $cancelledFired);
	}
}
