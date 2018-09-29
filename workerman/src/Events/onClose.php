<?php
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

return function ($stdObject, AsyncTcpConnection $conn) {
	echo 'Connection Closed, Shutting Down'.PHP_EOL;
	echo exec('php '.__DIR__.'/../../start.php stop').PHP_EOL;
	//$conn->reconnect(5);
	$conn->close();
	Worker::stopAll();
	Worker::exitAndClearAll();
};
