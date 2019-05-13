<?php
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

return function ($stdObject) {
	/**
	* @var \GlobalData\Client
	*/
	global $global;
	//echo 'Update Info Timer Startup'.PHP_EOL;
    if ($stdObject->debug === true) {
        Worker::safeEcho('vps_update_info Getting Lock'.PHP_EOL);
    }
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
            $new = ['vps_update_info'];
        } while (!$global->cas('busy', $old, $new));
        if ($stdObject->debug === true) {
            Worker::safeEcho('vps_update_info Got Lock'.PHP_EOL);
        }
	    $task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
	    $task_connection->send(json_encode(array('type' => 'vps_update_info', 'args' => array('type' => $stdObject->type))));
	    $conn = $stdObject->conn;
	    $task_connection->onMessage = function ($task_connection, $task_result) use ($conn) {
		    /**
		    * @var \GlobalData\Client
		    */
		    global $global;
		    //Worker::safeEcho(var_dump($task_result,true));
		    $task_connection->close();
		    //Worker::safeEcho('Update Info Got Result, Forwarding It'.PHP_EOL);
		    $conn->send($task_result);
            if ($stdObject->debug === true) {
                Worker::safeEcho('vps_update_info, Freeing Lock'.PHP_EOL);
            }
            do {
                $old = $global->busy;
                $new = [];
            } while (!$global->cas('busy', $old, $new));
            if ($stdObject->debug === true) {
                Worker::safeEcho('vps_update_info, Lock Freed'.PHP_EOL);
            }
		    //Worker::safeEcho('Update Info Timer End'.PHP_EOL);
	    };
	    $task_connection->connect();
    }
};
