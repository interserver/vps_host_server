#!/usr/bin/env php
<?php
/**
 * VPS Functionality
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2018
 * @package MyAdmin
 * @category VPS
 */
if (ini_get('date.timezone') == '')
{
	ini_set('date.timezone', 'America/New_York');
}
if ((isset($_ENV['SHELL']) && $_ENV['SHELL'] == '/bin/sh') && file_exists('/cron.vps.disabled'))
{
	exit;
}
$url = 'https://myvps2.interserver.net/vps_queue.php';
echo "[" . date('Y-m-d H:i:s') . "] Daily Crontab Startup\n";
$cmd = dirname(__FILE__).'/vps_update_extra_info.php;';
//echo "Running Command: $cmd\n";
echo `$cmd`;
if (file_exists('/usr/bin/prlctl')) {
	$cmd = dirname(__FILE__).'/update_virtuozzo.sh;';
	echo `$cmd`;	
}
