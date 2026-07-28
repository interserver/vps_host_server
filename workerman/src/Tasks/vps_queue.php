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
				// Worker::getEventLoop() is not a React LoopInterface under Workerman v5;
				// use the bridge so React\Http runs on Workerman's live event loop.
				$loop = \MyAdmin\VpsHost\ReactLoopBridge::instance();
				$browser = new React\Http\Browser($loop);
			}
			$browser->get('https://myvps.interserver.net/vps_queue.php?action='.$cmd)->then(function (Psr\Http\Message\ResponseInterface $response) {
				$data = $response->getBody();
				Worker::safeEcho(`$data`);
			});
		}
	}
};
