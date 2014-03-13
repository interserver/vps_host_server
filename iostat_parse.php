#!/usr/bin/php
<?php
function format_size($size) {
	$mod = 1024;
	$units = explode(' ','B KB MB GB TB PB');
	for ($i = 0; $size > $mod; $i++) {
		$size /= $mod;
	}
	return round($size, 2) . ' ' . $units[$i];
}
if (!file_exists('/usr/bin/iostat'))
{
	echo "Installing iostat..";
	`yum -y install iostat;`;
	echo "done\n\n";
}
$block_size = 512;
$out = trim(`iostat`);
$lines = explode("\n", $out);
preg_match('/^(?P<os>[^\s]+)\s+(?P<version>[^\s]+)\s+\((?P<hostname>[^\)]+)\)\s+(?P<date>[^\s]+)\s+(?P<bits>[^\s]+)\s+\((?P<cpus>[^\s]+)\s+CPU\).*$/', $lines[0], $matches);
$info = array(
	'os' => $matches['os'],
	'version' => $matches['version'],
	'hostname' => $matches['hostname'],
	'date' => $matches['date'],
	'bits' => $matches['bits'],
	'cpus' => $matches['cpus'],
	'cpu' => array(),
	'disks' => array(),
	'mappings' => array(),
);
$out = explode("\n", trim(`ls -l /dev/vz | grep -- '->' | awk '{ print $11 " " $9 }' | sed s#"../"#""#g;`));
foreach ($out as $line)
{
	$parts = explode(' ', $line);
	$info['mappings'][$parts[0]] = $parts[1];
}
preg_match_all('/%(?P<fields>[a-z]+)[^a-z%]*/', $lines[2], $matches);
$fields = $matches['fields'];
preg_match_all('/[^\d]*(?P<values>\d+\.\d+)[^\d]*/', $lines[3], $matches);
$values = $matches['values'];
foreach  ($fields as $idx => $field)
{
	$info['cpu'][$field] = $values[$idx];
}
for ($x = 6; $x < sizeof($lines); $x++)
{
	preg_match('/^(?P<device>[^\s]+)\s+(?P<tps>[^\s]+)\s+(?P<blkreadsec>[^\s]+)\s+(?P<blkwritesec>[^\s]+)\s+(?P<blkread>\d+)\s+(?P<blkwrite>\d+)$/', $lines[$x], $matches);
	$info['disks'][$matches['device']] = array(
		'tps' => $matches['tps'],
		'blkreadsec' => $matches['blkreadsec'],
		'blkwritesec' => $matches['blkwritesec'],
		'blkread' => $matches['blkread'],
		'blkwrite' => $matches['blkwrite'],
	);
}
echo sprintf("CPU Usage");
foreach ($info['cpu'] as $idx => $value)
{
	echo sprintf(" %13s", $value.'% '.$idx);
}
echo "\n\n";
echo sprintf(" %36s %18s %36s\n", $info['os'], $info['bits'], $info['hostname']);
echo sprintf(" %36s %18s %36s\n", $info['version'], $info['cpus'] . ' cores', $info['date']);
echo "\n";
echo sprintf(" %20s %7s %15s %15s %15s %15s\n", "Target", "Type", "Read Speed", "Write Speed", "Total Read", "Total Written");
foreach ($info['disks'] as $device => $data)
{
	if (isset($info['mappings'][$device]))
	{
		$name = $info['mappings'][$device];
		$type = 'VPS';
	}
	else
	{
		$name = $device;
		$type = 'Disk';
	}
	echo sprintf(" %20s %7s %15s %15s %15s %15s\n", 
		$name, 
		$type, 
		format_size($data['blkreadsec']*$block_size).'/sec', 
		format_size($data['blkwritesec']*$block_size).'/sec', 
		format_size($data['blkread']*$block_size), 
		format_size($data['blkwrite']*$block_size)
	);
}
?>
