<?php
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

/**
* ran periodicaly to update our vps mapping files
*/
return function ($stdObject) {
    /**
    * @var \GlobalData\Client
    */
    global $global;
	if ($stdObject->debug === true) {
		Worker::safeEcho('Map Timer Startup'.PHP_EOL);
	}
	/** gets/sets global lock **/
    do {
        Worker::safeEcho('Map Timer Sleep 1s before retrying lock'.PHP_EOL);
        sleep(1);
    } while (!$global->cas('busy', 0, 1));
	if ($stdObject->debug === true) {
		Worker::safeEcho('Map Timer Send get_map'.PHP_EOL);
	}
	/** send get_map request to hub **/
	$stdObject->conn->send(json_encode(['type' => 'get_map']));
	/** release global lock **/
	$global->busy = 0;
	if ($stdObject->debug === true) {
		Worker::safeEcho('Map Timer End'.PHP_EOL);
	}
};
