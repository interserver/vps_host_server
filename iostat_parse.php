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
	if (!file_exists('/usr/bin/iostat'))
	{
		echo "Error installing iostat\n";
		exit;
	}
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


$info['procs'] = array();
$out = trim(`pidstat -l -C qemu-kvm|sed s#"/usr/libexec/qemu-kvm -name "#""#g | sed s#" -S -M rhel[0-9.]* -enable-kvm -m"#""#g | sed s#" -realtime mlock=off"#""#g | sed s#"-smp \([0-9]*\),.*"#"\1"#g |grep -v -e "^$" -e "^$(uname -s) " | awk '{ print $9 " " $3 " " $4 " " $5 " " $6 " " $7 " " $8 " " $10 " " $11 }' | sed s#"CPU CPU"#"CPU CPU ram cores"#g;`);
$lines = explode("\n", $out);
for ($x = 1; $x < sizeof($lines); $x++)
{
	$parts = explode(' ', $line);
	$data = array();
	list($vps, $data['pid'], $data['cpu_user'], $data['cpu_system'], $data['cpu_guest'], $data['cpu'], $data['which_cpu'], $data['ram'], $data['cores']) = $parts;
	$info['procs'][$vps] = $data;	
}
print_r($info['procs']);
/*
Command PID %usr %system %guest %CPU CPU ram cores
windows18535 1869 2.50 3.82 1.60 7.92 16 500 1
windows12173 6968 2.37 3.11 45.78 51.26 8 735 1
windows8077 7235 2.33 3.48 1.98 7.79 7 1469 1
windows22864 10995 0.01 0.03 0.22 0.26 6 1000 1
windows19431 12337 1.37 0.25 12.40 14.03 19 500 1
windows19379 13114 1.49 3.23 0.20 4.92 18 500 1
windows23497 15756 0.10 0.21 0.49 0.80 19 1000 1
windows21878 18919 0.97 1.99 1.19 4.14 3 1000 1
windows23396 23116 0.41 0.62 5.74 6.77 17 1000 1
windows22301 24178 1.30 0.37 6.11 7.78 9 1000 1
windows23391 24381 0.07 0.10 0.04 0.20 11 1000 1
windows23319 27385 0.48 0.68 6.01 7.17 2 1000 1
windows22412 27888 0.91 0.49 0.41 1.81 15 1000 1
windows23325 29893 0.29 0.33 0.06 0.68 21 1000 1
windows11981 31488 2.35 11.21 5.51 19.06 12 2204 8
*/

?>
