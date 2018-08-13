<?php
use Workerman\Worker;
use Workerman\Lib\Timer;
use Workerman\Connection\AsyncTcpConnection;

return function($stdObject, $worker) {
	$global->lastMessageTime = 0;
	$stdObject->debug = true;
	$stdObject->conn = null;
	$stdObject->var = null;
	$stdObject->vps_list = array();
	$stdObject->bandwidth = null;
	$stdObject->traffic_last = null;
	$stdObject->timers = array();
	$stdObject->ipmap = array();
	$stdObject->running = array();
	$stdObject->type = file_exists('/usr/sbin/vzctl') ? 'vzctl' : 'kvm';
	//Events::update_network_dev();
	$stdObject->get_vps_ipmap();
	if (isset($_SERVER['HOSTNAME']))
		$stdObject->hostname = $_SERVER['HOSTNAME'];
	else
		$stdObject->hostname = trim(shell_exec('hostname -f 2>/dev/null||hostname'));
	if (!file_exists(__DIR__.'/../myadmin.crt')) {
		Worker::safeEcho("Generating new SSL Certificate for encrypted communications\n");
		echo shell_exec('echo -e "US\nNJ\nSecaucus\nInterServer\nAdministration\n'.$stdObject->hostname.'"|/usr/bin/openssl req -utf8 -batch -newkey rsa:2048 -keyout '.__DIR__.'/../myadmin.key -nodes -x509 -days 365 -out '.__DIR__.'/../myadmin.crt -set_serial 0');
	}
	global $global;
	$global = new \GlobalData\Client('127.0.0.1:55553');	 // initialize the GlobalData client
	if (!isset($global->busy))
		$global->busy = 0;
	$stdObject->config = array_merge(parse_ini_file(__DIR__.'/../../config.ini.dist', true), file_exists(__DIR__.'/../../config.ini') ? parse_ini_file(__DIR__.'/../../config.ini', true) : []);        
	if ($stdObject->config['options']['use_ssl'] == 1) {
		$ws_connection= new AsyncTcpConnection('ws://my3.interserver.net:7272', $stdObject->getSslContext());
		$ws_connection->transport = 'ssl';
	} else
		$ws_connection= new AsyncTcpConnection('ws://my3.interserver.net:7271');
	$ws_connection->onConnect = array($stdObject, 'onConnect');
	$ws_connection->onMessage = array($stdObject, 'onMessage');
	$ws_connection->onError = array($stdObject, 'onError');
	$ws_connection->onClose = array($stdObject, 'onClose');
	$ws_connection->onWorkerStop = array($stdObject, 'onWorkerStop');
	$ws_connection->connect();
};
