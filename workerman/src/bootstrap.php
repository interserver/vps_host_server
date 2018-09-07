<?php

if (strpos(strtolower(PHP_OS), 'win') !== 0) {
	if (!extension_loaded('pcntl')) {
		exit("Please install pcntl extension. See http://doc3.workerman.net/appendices/install-extension.html\n");
	}
	if (!extension_loaded('posix')) {
		exit("Please install posix extension. See http://doc3.workerman.net/appendices/install-extension.html\n");
	}
}
ini_set('display_errors', 'on');
if (ini_get('date.timezone') == '') {
	ini_set('date.timezone', 'America/New_York');
}
if (ini_get('default_socket_timeout') < 1200 && ini_get('default_socket_timeout') > 1) {
	ini_set('default_socket_timeout', 1200);
}

include __DIR__.'/../vendor/autoload.php';
include_once __DIR__.'/stdObject.php';
