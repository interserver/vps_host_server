<?php

use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\AsyncTcpConnection;

return function ($stdObject, $name, $interval = false, $callable = false) {
    Worker::safeEcho("addTimer called with ({$name}, <callable>, ".var_export($interval, true).") called timer set? ".var_export(isset($stdObject->timers[$name]), true).PHP_EOL);
    if (isset($stdObject->timers[$name])) {
        Worker::safeEcho("addTimer deleting timer {$name} id {$stdObject->timers[$name]}\n");
		Timer::del($stdObject->timers[$name]);
	}
    if ($callable === false)
        $callable = array($stdObject, $name);
    if ($interval === false)
        $interval = $stdObject->config['timers'][$name];
	$stdObject->timers[$name] = Timer::add($interval, $callable);
    Worker::safeEcho("addTimer adding timer {$name} every {$interval} got timer id {$stdObject->timers[$name]}\n");
};
