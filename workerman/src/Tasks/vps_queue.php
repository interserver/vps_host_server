<?php
use Workerman\Worker;

return function ($stdObject, $cmds) {
	foreach ($cmds as $cmd) {
		if (preg_match('/\.php$', $cmd) && file_exists(__DIR__.'/../'.$cmd)) {
			include __DIR__.'/../../'.$cmd;
		} elseif (preg_match('/(\/[^ ]+).*$/m', $cmd, $matches)) {
			Worker::safeEcho(`$cmd`);
		} else {
			if (!isset($browser)) {
				$loop = Worker::getEventLoop();
				$browser = new React\Http\Browser($loop);
			}
			$browser->get('https://mynew.interserver.net/vps_queue.php?action='.$cmd)->then(function (Psr\Http\Message\ResponseInterface $response) {
				$data = $response->getBody();
				Worker::safeEcho(`$data`);
			});
		}
	}
};
