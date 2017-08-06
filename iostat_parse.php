#!/usr/bin/php
<?php
/**
 * @param $size
 * @return string
 */
function format_size($size) {
	$mod = 1024;
	$units = explode(' ', 'B KB MB GB TB PB');
	for ($i = 0; $size > $mod; $i++) {
		$size /= $mod;
	}
	return round($size, 2) . (strlen($units[$i]) == 1 ? '  ' : ' ') . $units[$i];
}

if (!file_exists('/usr/bin/iostat'))
{
	echo 'Installing iostat..';
	if (trim(`which yum;`) != '')
	{
		echo 'CentOS Detected...';
		`yum -y install sysstat;`;
	}
	elseif (trim(`which apt-get;`) != '')
	{
		echo 'Ubuntu Detected...';
		`apt-get -y install sysstat;`;
//        `echo -e 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades;`;
	}
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
	'cpu' => [],
	'disks' => [],
	'mappings' => []
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
for ($x = 6, $xMax = count($lines); $x < $xMax; $x++)
{
	preg_match('/^(?P<device>[^\s]+)\s+(?P<tps>[^\s]+)\s+(?P<blkreadsec>[^\s]+)\s+(?P<blkwritesec>[^\s]+)\s+(?P<blkread>\d+)\s+(?P<blkwrite>\d+)$/', $lines[$x], $matches);
	$info['disks'][$matches['device']] = array(
		'tps' => $matches['tps'],
		'blkreadsec' => $matches['blkreadsec'],
		'blkwritesec' => $matches['blkwritesec'],
		'blkread' => $matches['blkread'],
		'blkwrite' => $matches['blkwrite']
	);
}

$info['procs'] = array();
if (!file_exists('/usr/libexec/qemu-kvm') && file_exists('/usr/bin/kvm'))
{
	$out = `pidstat -l -C kvm;`;
	$regex = '/^(?P<time>[0-9][0-9]:[0-9][0-9]:[0-9][0-9] [AP]M)\s+(?P<pid>\d+)\s+(?P<cpu_user>[\d\.]+)\s+(?P<cpu_system>[\d\.]+)\s+(?P<cpu_guest>[\d\.]+)\s+(?P<cpu_all>[\d\.]+)\s+(?P<cpu_num>[\d]+)\s+\/usr\/bin\/kvm.* \-m (?<ram>[\d]*)\s.*sockets=(?P<cores>\d+),.*\-name (?<vps>[^\s]+)\s+.*$/';
}
else
{
	$out = `pidstat -l -C qemu-kvm;`;
	$regex = '/^(?P<time>[0-9][0-9]:[0-9][0-9]:[0-9][0-9] [AP]M)\s+(?P<pid>\d+)\s+(?P<cpu_user>[\d\.]+)\s+(?P<cpu_system>[\d\.]+)\s+(?P<cpu_guest>[\d\.]+)\s+(?P<cpu_all>[\d\.]+)\s+(?P<cpu_num>[\d]+)\s+\/usr\/libexec\/qemu-kvm \-name (?<vps>[^\s]+)\s+.* \-m (?<ram>[\d]*)\s.*sockets=(?P<cores>\d+),.*$/';
}
//echo "$out\n";
/*
$out = `pidstat -l -C qemu-kvm |\
 sed s#"/usr/libexec/qemu-kvm -name "#""#g |\
 sed s#" -S -M rhel[0-9.]* -enable-kvm -m"#""#g |\
 sed s#" -realtime mlock=off"#""#g |\
 sed s#"-smp \([0-9]*\),.*"#"\1"#g |\
 grep -v -e "^$" -e "^$(uname -s) " |\
 awk '{ print $9 " " $3 " " $4 " " $5 " " $6 " " $7 " " $8 " " $10 " " $11 " z" }' |\
 sed s#"CPU CPU"#"CPU CPU ram cores "#g;
`;
*/
//echo "$out\n";
$lines = explode("\n", $out);
for ($x = 1, $xMax = count($lines); $x < $xMax; $x++)
{
	if (!preg_match($regex, $lines[$x], $matches))
		continue;
	//preg_match('/^(?P<time>[0-9][0-9]:[0-9][0-9]:[0-9][0-9] [AP]M)\s+(?P<pid>\d+)\s+(?P<cpu_user>[\d\.]+)\s+(?P<cpu_system>[\d\.]+)\s+(?P<cpu_guest>[\d\.]+)\s+(?P<cpu_all>[\d\.]+)\s+(?P<cpu_num>[\d]+)\s+/', $lines[$x], $matches);
	//print_r($matches);
	//echo "$lines[$x]\n";
	$vps = $matches['vps'];
	$data = array(
		'pid' => $matches['pid'],
		'cpu_user' => $matches['cpu_user'],
		'cpu_system' => $matches['cpu_system'],
		'cpu_guest' => $matches['cpu_guest'],
		'cpu_all' => $matches['cpu_all'],
		'which_cpu' => $matches['cpu_num'],
		'ram' => $matches['ram'],
		'cores' => $matches['cores']
	);
	$info['procs'][$vps] = $data;
}
//print_r($info['procs']);



echo sprintf('CPU Usage');
foreach ($info['cpu'] as $idx => $value)
{
	echo sprintf(' %13s', $value.'% '.$idx);
}
echo "\n\n";
echo sprintf(" %36s %18s %36s\n", $info['os'], $info['bits'], $info['hostname']);
echo sprintf(" %36s %18s %36s\n", $info['version'], $info['cpus'].' cores', $info['date']);
echo "\n";
echo sprintf(" %20s %7s %15s %15s %15s %15s %15s %8s %8s %8s\n", 'Target', 'Type', 'Read Speed', 'Write Speed', 'Total Read', 'Total Written', 'CPU % User', '% System', '% Guest', '% All');
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
	echo sprintf(
		' %20s %7s %15s %15s %15s %15s',
		$name,
		$type,
		format_size($data['blkreadsec']*$block_size).'/sec',
		format_size($data['blkwritesec']*$block_size).'/sec',
		format_size($data['blkread']*$block_size),
		format_size($data['blkwrite']*$block_size)
	);
	if ($type == 'VPS' && isset($info['procs'][$name]))
	{
		echo sprintf(' %15s %8s %8s %8s', $info['procs'][$name]['cpu_user'], $info['procs'][$name]['cpu_system'], $info['procs'][$name]['cpu_guest'], $info['procs'][$name]['cpu_all']);
	}
	echo "\n";
}
