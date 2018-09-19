<?php
use Workerman\Worker;

return function ($stdObject, Worker $worker) {
	/**
	* @var \GlobalData\Client
	*/
	global $global;
	if ($settings['vmstat']['enable'] === TRUE) {
		@shell_exec('killall vmstat');
		@pclose($worker->process_handle);
	}
};
