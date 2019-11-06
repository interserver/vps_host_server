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
	$task_connection = new AsyncTcpConnection('Text://127.0.0.1:55552');
	$task_connection->send(json_encode(array('type' => 'vps_get_list', 'lock' => true, 'args' => array('type' => $stdObject->type))));
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
		//Worker::safeEcho(var_dump($task_result,true));
		$task_connection->close();
		//Worker::safeEcho('Get List Got Result, Forwarding It'.PHP_EOL);
		$conn->send($task_result);
		//Worker::safeEcho('Get List Timer End'.PHP_EOL);
		
	};
	$task_connection->connect();
};
