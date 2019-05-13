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
	/** send get_map request to hub **/
	$stdObject->conn->send(json_encode(['type' => 'get_map']));
	/** release global lock **/
};
