#!/usr/bin/env php
<?php
/**
 * VPS Functionality
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2018
 * @package MyAdmin
 * @category VPS
 */
if (ini_get('date.timezone') == '') {
	ini_set('date.timezone', 'America/New_York');
}
if ((isset($_ENV['SHELL']) && $_ENV['SHELL'] == '/bin/sh') && file_exists('/cron.vps.disabled')) {
	exit;
}
$url = 'https://my-web-3.interserver.net/vps_queue.php';
echo "[" . date('Y-m-d H:i:s') . "] Daily Crontab Startup\n";
$cmd = dirname(__FILE__).'/provirted.phar cron host-info-extra;';
//echo "Running Command: $cmd\n";
echo `$cmd`;
if (file_exists('/usr/bin/prlctl')) {
	$cmd = dirname(__FILE__).'/provirted.phar cron virtuozzo-update;';
	echo `$cmd`;
}
$cmd = 'echo > '.__DIR__.'/workerman/stdout.log';
`$cmd`;
