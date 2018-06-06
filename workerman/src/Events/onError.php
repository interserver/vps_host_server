<?php
use Workerman\Worker;

return function($stdObject, $connection, $code, $msg){
	Worker::safeEcho("error: {$msg}\n");
};
