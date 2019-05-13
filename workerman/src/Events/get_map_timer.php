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
		Worker::safeEcho('Map Timer Getting Lock'.PHP_EOL);
	}
	/** gets/sets global lock **/
    for ($x = 0; $x < 10; $x++) {
        $old = $global->busy;
        Worker::safeEcho('old: '.var_export($old, true).PHP_EOL);
        if (count($old) > 0) {
            sleep(1);
        }
    }
    do {        
        $old = [];
        $new = ['get_map'];
    } while (!$global->cas('busy', $old, $new));
	if ($stdObject->debug === true) {
		Worker::safeEcho('Map Timer Got Lock, Send get_map'.PHP_EOL);
	}
	/** send get_map request to hub **/
	$stdObject->conn->send(json_encode(['type' => 'get_map']));
	/** release global lock **/
    if ($stdObject->debug === true) {
        Worker::safeEcho('Map Timer End, Freeing Lock'.PHP_EOL);
    }
    do {
        $old = $global->busy;
        $new = [];
    } while (!$global->cas('busy', $old, $new));
	if ($stdObject->debug === true) {
		Worker::safeEcho('Map Timer End, Lock Freed'.PHP_EOL);
	}
};
