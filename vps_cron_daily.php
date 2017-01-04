#!/usr/bin/php -q
<?php
/**
 * VPS Functionality
 * Last Changed: $LastChangedDate: 2016-10-31 04:29:46 -0400 (Mon, 31 Oct 2016) $
 * @author $Author: detain $
 * @version $Revision: 21715 $
 * @copyright 2017
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
$cmd = dirname(__FILE__) . '/vps_update_extra_info.php;';
//echo "Running Command: $cmd\n";
echo `$cmd`;
?>
