<?php
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

return function ($stdObject, AsyncTcpConnection $connection, $code, $msg) {
	Worker::safeEcho("error: {$msg}\n");
};
