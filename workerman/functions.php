<?php

function vps_update_info_timer() {
	global $global, $settings;
	$task_connection = new AsyncTcpConnection('Text://'.$settings['servers']['task']['ip'].':'.$settings['servers']['task']['port']);
	$task_connection->send(json_encode(['function' => 'async_hyperv_get_list', 'args' => []]));
	$task_connection->onMessage = function($task_connection, $task_result) use ($task_connection) {
		 //var_dump($task_result);
		 $task_connection->close();
	};
	$task_connection->connect();
}

function vps_queue($cmds) {
	foreach ($cmds as $cmd) {
		if (preg_match('/\.php$', $cmd) && file_exists(__DIR__.'/../'.$cmd))
			include __DIR__.'/../'.$cmd;
		elseif (preg_match('/(\/[^ ]+).*$/m', $cmd, $matches))
			echo `$cmd`;
		else {
			if (!isset($react_client)) {
				$loop = Worker::getEventLoop();
				$react_factory = new React\Dns\Resolver\Factory();
				$react_dns = $react_factory->createCached('8.8.8.8', $loop);
				$react_factory = new React\HttpClient\Factory();
				$react_client = $react_factory->create($loop, $react_dns);
			}
			$request = $client->request('GET', 'https://myvps2.interserver.net/vps_queue.php?action='.$cmd);
			$request->on('error', function(Exception $e) use ($cmd) {
				echo "CMD {$cmd} Exception Error {$e->getMessage()}\n";
			});
			$request->on('response', function ($response) {
				$response->on('data', function ($data, $response) {
					echo `$data`;
				});
			});
			$request->end();
		}
	}
}

function vps_queue_timer() {
	global $global, $settings;
	$task_connection = new AsyncTcpConnection('Text://'.$settings['servers']['task']['ip'].':'.$settings['servers']['task']['port']); // Asynchronous link with the remote task service
	$task_connection->send(json_encode(['function' => 'vps_queue', 'args' => $global->settings['vps_queue']['cmds']])); // send data
	$task_connection->onMessage = function($task_connection, $task_result) use ($task_connection) {	// get the result asynchronously
		 //var_dump($task_result);
		 $task_connection->close(); // remember to turn off the asynchronous link after getting the result
	};
	$task_connection->connect(); // execute async link
}

function validIp($ip, $display_errors = true, $support_ipv6 = false) {
	if (version_compare(PHP_VERSION, '5.2.0') >= 0)
	{
		if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
			if ($support_ipv6 === false || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)
				return false;
	} else {
		if (!preg_match("/^[0-9\.]{7,15}$/", $ip))
		{
			// don't display errors cuz this gets called w/ a blank entry when people didn't even submit anything yet
			//add_output('<font class="error">IP '.$ip.' Too short/long</font>');
			return false;
		}
		$quads = explode('.', $ip);
		$numquads = count($quads);
		if ($numquads != 4)
		{
			if ($display_errors)
				error_log('<font class="error">IP '.$ip.' Too many quads</font>');
			return false;
		}
		for ($i = 0; $i < 4; $i++)
			if ($quads[$i] > 255)
			{
				if ($display_errors)
					error_log('<font class="error">IP '.$ip.' number '.$quads[$i].' too high</font>');
				return false;
			}
	}
	return true;
}
