<?php
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

/**
* ran periodicaly to update our vps mapping files
*/
return function ($stdObject) {
    //Worker::safeEcho("[HeartBeat] Check starting".PHP_EOL);
    /**
    * @var \GlobalData\Client
    */
    global $global;
	$timeSinceMessage = time() - $global->lastMessageTime;
	if ($timeSinceMessage >= $stdObject->config['heartbeat']['timeout']) {
		Worker::safeEcho("Time Since Last Message {$timeSinceMessage}, Closing Connection".PHP_EOL);
		$stdObject->conn->close();
        Worker::stopAll();
	} elseif ($timeSinceMessage >= $stdObject->config['heartbeat']['ping_when_silent_for']) {
		//Worker::safeEcho("Time Since Last Message {$timeSinceMessage}, Sending Ping".PHP_EOL);
		$stdObject->sendPing();
	}
};
