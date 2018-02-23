<?php
$settings = array(
	'heartbeat' => array(
		'enable' => FALSE,
		'check_interval' => 60,
		'timeout' => 600,
	),
	'vps_queue' => array(
		$cmds = array(),
	),
	'timers' => array(
		'vps_update_info' => 600,
		'vps_queue' => 60,
		'getnewvps' => 60,
		'vps_traffic_new' => 60,
		'getslicemap' => 60,
		'getipmap' => 60,
		'getvncmap' => 60,
		'getqueue' => 60,
		'vps_get_list' => 60,
		'vps_update_extra_info' => 86400,
		'update_virtuozzo' => 86400,
	),
);

if (file_exists('/proc/vz')) {
	$settings['vps_queue']['cmds'][] = '/root/cpaneldirect/cpu_usage_updater.sh 2>/root/cpaneldirect/cron.cpu_usage >&2 &';
}
$settings['vps_queue']['cmds'][] = 'vps_update_info.php';
$settings['vps_queue']['cmds'][] = 'getnewvps';
$settings['vps_queue']['cmds'][] = 'getslicemap';
if (!file_exists('/usr/sbin/vzctl')) {
	$settings['vps_queue']['cmds'][] = 'getipmap';
	$settings['vps_queue']['cmds'][] = 'getvncmap';
}
$settings['vps_queue']['cmds'][] = 'getqueue';

return $settings;
