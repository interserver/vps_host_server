<?php
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

/**
* gets a listing of vps services to send to the hub
*/
return function ($stdObject) {
	/**
	* @var \GlobalData\Client
	*/
	global $global;
	if ($stdObject->debug === true) {
		Worker::safeEcho('vps_get_list Startup, Getting Lock'.PHP_EOL);
	}
	/** gets/sets global lock **/
    for ($x = 0; $x < 10; $x++) {
        $old = $global->busy;
        Worker::safeEcho('old: '.var_export($old, true).PHP_EOL);
        if (count($old) == 0) {
            break;
        }
        sleep(1);
    }
    if (count($old) == 0) {
        do {        
            $old = [];
            $new = ['vps_get_list'];
        } while (!$global->cas('busy', $old, $new));
	    if ($stdObject->debug === true) {
		    Worker::safeEcho('vps_get_list Got Lock'.PHP_EOL);
	    }
	    $task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
	    $task_connection->send(json_encode(array('type' => 'vps_get_list', 'args' => array('type' => $stdObject->type))));
	    $conn = $stdObject->conn;
	    if ($stdObject->debug === true) {
		    Worker::safeEcho('vps_get_list Launching Task Processor'.PHP_EOL);
	    }
	    $task_connection->onMessage = function ($task_connection, $task_result) use ($stdObject, $conn) {
		    /**
		    * @var \GlobalData\Client
		    */
		    global $global;
		    if ($stdObject->debug === true) {
			    Worker::safeEcho('vps_get_list Got Task Processor Result, Closing Task Connection'.PHP_EOL);
		    }
		    //var_dump($task_result);
		    $task_connection->close();
		    if ($stdObject->debug === true) {
			    Worker::safeEcho('vps_get_list Forwarding Result'.PHP_EOL);
		    }
		    $conn->send($task_result);
            do {
                $old = $global->busy;
                $new = [];
            } while (!$global->cas('busy', $old, $new));
            if ($stdObject->debug === true) {
                Worker::safeEcho('vps_get_list, Lock Freed'.PHP_EOL);
            }
	    };
	    $task_connection->connect();
    }
};
