<?php
use Workerman\Worker;

return function ($stdObject, $conn) {
	echo 'Connection Closed, Shutting Down'.PHP_EOL;
	Worker::stopAll();
	//$conn->reconnect(5);
	$conn->close();
};
