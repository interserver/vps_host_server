<?php
$settings = [
	'servers' => [
		'task' => [
			'ip' => '127.0.0.1',
			'port' => 55552,
			'count' => 5,
		],
		'globaldata' => [
			'ip' => '127.0.0.1',
			'port' => 55553,
		],
		'ws' => [
			'ip' => '0.0.0.0',
			'port' => 55554,
		],
		'http' => [
			'enable' => TRUE,
			'ip' => '0.0.0.0',
			'port' => 55555,
			'count' => 2,
		],
	],
	'auth' => [
		'enable' => FALSE,
		'timeout' => 30,
	],
	'vmstat' => [
		'enable' => FALSE,
	],
	'phptty' => [
		'enable' => FALSE,
		'cmd' => 'htop', // Command. For example 'tail -f /var/log/nginx/access.log'.
		'client_input' => TRUE, // Whether to allow client input.
	],
	'heartbeat' => [
		'enable' => FALSE,
		'check_interval' => 60,
		'timeout' => 600,
	],
	'timers' => [
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
	],
];

return $settings;
