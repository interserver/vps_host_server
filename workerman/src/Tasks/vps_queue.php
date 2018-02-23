<?php
use Workerman\Worker;

return function($stdObject, $cmds) {
	foreach ($cmds as $cmd) {
		if (preg_match('/\.php$', $cmd) && file_exists(__DIR__.'/../'.$cmd))
			include __DIR__.'/../../'.$cmd;
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
};
