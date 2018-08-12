<?php
use Workerman\Worker;

return function($stdObject, $conn) {
	echo 'Connection Closed, Shutting Down'.PHP_EOL;
	//$conn->close();
	$conn->reConnect(5);
	//Worker::stopAll();
};
