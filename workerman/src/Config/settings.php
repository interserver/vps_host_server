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
		'get_new_vps' => 60,
		'vps_traffic_new' => 60,
		'get_slice_map' => 60,
		'get_ip_map' => 60,
		'get_vnc_map' => 60,
		'get_queue' => 60,
		'vps_get_list' => 60,
		'vps_update_extra_info' => 86400,
		'update_virtuozzo' => 86400,
	),
);

if (file_exists('/proc/vz')) {
	$settings['vps_queue']['cmds'][] = '/root/cpaneldirect/cpu_usage_updater.sh 2>/root/cpaneldirect/cron.cpu_usage >&2 &';
}
$settings['vps_queue']['cmds'][] = 'vps_update_info.php';
$settings['vps_queue']['cmds'][] = 'get_new_vps';
$settings['vps_queue']['cmds'][] = 'get_slice_map';
if (!file_exists('/usr/sbin/vzctl')) {
	$settings['vps_queue']['cmds'][] = 'get_ip_map';
	$settings['vps_queue']['cmds'][] = 'get_vnc_map';
}
$settings['vps_queue']['cmds'][] = 'get_queue';

return $settings;
