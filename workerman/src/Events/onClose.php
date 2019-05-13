<?php
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

return function ($stdObject, AsyncTcpConnection $conn) {
    $reload = true;
    if ($reload == true) {
        Worker::safeEcho('Connection Closed, Reconnecting.'.PHP_EOL);
        //$conn->reconnect(5);
        Worker::stopAll();
    } else {
	    Worker::safeEcho('Connection Closed, Shutting Down'.PHP_EOL);
	    Worker::safeEcho(exec('php '.__DIR__.'/../../start.php stop').PHP_EOL);
	    //$conn->close();
	    Worker::stopAll();
	    //Worker::exitAndClearAll();
    }
};
