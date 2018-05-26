<?php
return function($stdObject, $params) {
    $root_used = trim(`df -P /| awk '{ print $5 }' |grep % | sed s#"%"#""#g`);
    if ($root_used > 90)
    {
        $hostname = trim(`hostname;`);
        mail('hardware@interserver.net', $root_used.'% Disk Usage on '.$hostname, $root_used.'% Disk Usage on '.$hostname);
    }
    $url = 'https://myvps2.interserver.net/vps_queue.php';
    $server = array();
    switch (trim(`uname -p`))
    {
        case 'i686':
            $server['bits'] = 32;
            break;
        case 'x86_64':
            $server['bits'] = 64;
            break;
    }
    $server['raid_building'] = (trim(`grep -v idle /sys/block/md*/md/sync_action 2>/dev/null`) == '' ? 0 : 1);
    $server['kernel'] = trim(`uname -r`);
    $server['load'] = trim(`cat /proc/loadavg | cut -d" " -f1`);

    if (file_exists('/dev/bcache0') && $server['load'] >= 2.00)
    {
        $server['load'] -= 2.00;
    }
    $server['ram'] = trim(`free -m | grep Mem: | awk '{ print \$2 }'`);
    $server['cpu_model'] = trim(`grep "model name" /proc/cpuinfo | head -n1 | cut -d: -f2-`);
    $server['cpu_mhz'] = trim(`grep "cpu MHz" /proc/cpuinfo | head -n1 | cut -d: -f2-`);
//        $servers['cores'] = trim(`echo \$((\$(lscpu |grep "^Core(s) per socket" | awk '{ print \$4 }') * \$(lscpu |grep "^Socket" | awk '{ print \$2 }')))`);
//        $servers['cores'] = trim(`echo \$((\$(cat /proc/cpuinfo|grep '^physical id' | sort | uniq | wc -l) * \$(grep '^cpu cores' /proc/cpuinfo  | tail -n 1|  awk '{ print \$4 }')))`);
//        $servers['cores'] = trim(`lscpu |grep "^CPU(s)"| awk '{ print $2 }';`);
    $server['cores'] = trim(`grep '^processor' /proc/cpuinfo |wc -l;`);
    $cmd = 'df --block-size=1G |grep "^/" | grep -v -e "/dev/mapper/" | awk \'{ print $1 ":" $2 ":" $3 ":" $4 ":" $6 }\'
for i in $(pvdisplay -c|grep :); do 
  d="$(echo "$i" | cut -d: -f1 | sed s#" "#""#g)";
  blocksize="$(echo "$i" | cut -d: -f8)";
  total="$(echo "$(echo "$i" | cut -d: -f9) * $blocksize / (1024 * 1024)" | bc -l | cut -d\. -f1)";
  free="$(echo "$(echo "$i" | cut -d: -f10) * $blocksize / (1024 * 1024)" | bc -l | cut -d\. -f1)";
  used="$(echo "$(echo "$i" | cut -d: -f11) * $blocksize / (1024 * 1024)" | bc -l | cut -d\. -f1)";
  target="$(echo "$i" | cut -d: -f2)";
  echo "$d:$total:$used:$free:$target";
done
';
    $server['mounts'] = str_replace("\n", ',', trim(`$cmd`));
    $server['raid_status'] = trim(`/root/cpaneldirect/check_raid.sh --check=WARNING 2>/dev/null`);
    if (!file_exists('/usr/bin/iostat'))
    {
        echo "Installing iostat..";
        if (trim(`which yum;`) != '')
        {
            echo "CentOS Detected...";
            `yum -y install sysstat;`;
        }
        elseif (trim(`which apt-get;`) != '')
        {
            echo "Ubuntu Detected...";
            `apt-get -y install sysstat;`;
//                `echo -e 'APT::Periodic::Update-Package-Lists "1";\nAPT::Periodic::Unattended-Upgrade "1";\n' > /etc/apt/apt.conf.d/20auto-upgrades;`;
        }
        echo "done\n\n";
        if (!file_exists('/usr/bin/iostat'))
        {
            echo "Error installing iostat\n";
        }
    }
    if (file_exists('/usr/bin/iostat'))
    {
        $server['iowait'] = trim(`iostat -c  |grep -v "^$" | tail -n 1 | awk '{ print $4 }';`);
    }
    $cmd = 'if [ "$(which ioping 2>/dev/null)" = "" ]; then 
  if [ -e /usr/bin/apt-get ]; then 
    apt-get update; 
    apt-get install -y ioping; 
  else
    if [ "$(which rpmbuild 2>/dev/null)" = "" ]; then 
      yum install -y rpm-build; 
    fi;
    if [ "$(which make 2>/dev/null)" = "" ]; then 
      yum install -y make;
    fi;
    if [ ! -e /usr/include/asm/unistd.h ]; then
      yum install -y kernel-headers;
    fi;
    wget http://mirror.trouble-free.net/tf/SRPMS/ioping-0.9-1.el6.src.rpm -O ioping-0.9-1.el6.src.rpm; 
    export spec="/$(rpm --install ioping-0.9-1.el6.src.rpm --nomd5 -vv 2>&1|grep spec | cut -d\; -f1 | cut -d/ -f2-)"; 
    rpm --upgrade $(rpmbuild -ba $spec |grep "Wrote:.*ioping-0.9" | cut -d" " -f2); 
    rm -f ioping-0.9-1.el6.src.rpm; 
  fi; 
fi;';
       `$cmd`;
       $cmd = 'if [ "$(which vzctl 2>/dev/null)" = "" ]; then 
  iodev="/$(pvdisplay -c |grep -v -e centos -e backup -e vz-snap |grep :|cut -d/ -f2- |cut -d: -f1|head -n 1)";
else 
  iodev=/vz; 
fi; 
ioping -c 3 -s 100m -D -i 0 ${iodev} -B | cut -d" " -f2;';
//ioping -q -i 0 -w 3 -s 100m -S 100m -B ${iodev} | cut -d" " -f4;';
//ioping -q -i 0 -w 3 -s 10m -S 100m -B ${iodev} | cut -d" " -f4;';
//ioping -B -R ${iodev} | cut -d" " -f4;';
//ioping -c 3 -s 100m -D -i 0 ${iodev} -B | cut -d" " -f6;';
    $server['ioping'] = trim(`$cmd`);
    if (file_exists('/usr/sbin/vzctl'))
    {
        $out = trim(`export PATH="\$PATH:/bin:/usr/bin:/sbin:/usr/sbin";df -B G /vz | grep -v ^Filesystem | awk '{ print \$2 " " \$4 }' |sed s#"G"#""#g;`);
    } else {
        if (trim(`lvdisplay  |grep 'Allocated pool';`) == '')
        {
            $parts = explode(':', trim(`export PATH="\$PATH:/sbin:/usr/sbin"; pvdisplay -c|grep : |grep -v -e centos -e backup`));
            $pesize = $parts[7];
            $totalpe = $parts[8];
            $freepe = $parts[9];
            $totalg = ceil($pesize * $totalpe / 1000000);
            $freeg = ceil($pesize * $freepe / 1000000);
            $out = "$totalg $freeg";
        } else {
            //$totalg = trim(`lvdisplay --units g /dev/vz/thin |grep 'LV Size' | sed s#"^.*LV Size"#""#g | sed s#"GiB"#""#g | sed s#" "#""#g | cut -d\. -f1`);
            //$freeg = trim(`echo "\$(lvdisplay --units g /dev/vz/thin |grep 'LV Size' | sed s#"LV Size"#""#g | sed s#"GiB"#""#g) - ( \$(lvdisplay --units g /dev/vz/thin |grep 'Allocated .*data' | sed s#"Allocated.*data"#""#g |sort -nr| head -n1 |sed s#"%"#""#g) / 100 * \$(lvdisplay --units g /dev/vz/thin |grep 'LV Size' | sed s#"LV Size"#""#g | sed s#"GiB"#""#g) )" |bc -l |cut -d\. -f1`);
            // this one doubles the space usage to make it stop at 50%
            //$freeg = trim(`echo "\$(lvdisplay --units g /dev/vz/thin |grep 'LV Size' | sed s#"LV Size"#""#g | sed s#"GiB"#""#g) - ( \$(lvdisplay --units g /dev/vz/thin |grep 'Allocated .*data' | sed s#"Allocated.*data"#""#g |sort -nr| head -n1 |sed s#"%"#""#g) / 100 * \$(lvdisplay --units g /dev/vz/thin |grep 'LV Size' | sed s#"LV Size"#""#g | sed s#"GiB"#""#g) * 2 )" |bc -l |cut -d\. -f1`);
$TOTAL_GB = '$(lvdisplay --units g /dev/vz/thin |grep "LV Size" | sed s#"^.*LV Size"#""#g | sed s#"GiB"#""#g | sed s#" "#""#g | cut  -d\. -f1)';
$USED_PCT = '$(lvdisplay --units g /dev/vz/thin |grep "Allocated .*data" | sed s#"Allocated.*data"#""#g |sort -nr| head -n1 |sed s#"%"#""#g)';
$GB_PER_PCT = '$(echo "'.$TOTAL_GB.' / 100" |bc -l | cut -d\. -f1)';
$USED_GB = '$(echo "'.$USED_PCT.' * '.$GB_PER_PCT.'" | bc -l)';
$MAX_PCT =  60;
$FREE_PCT = '$(echo "'.$MAX_PCT.' - '.$USED_PCT.'" |bc -l)';
$FREE_GB = '$(echo "'.$GB_PER_PCT.' * '.$FREE_PCT.'" |bc -l)';
//echo 'Total GB '.$TOTAL_GB.'Used % '.$USED_PCT.'GB Per % '.$GB_PER_PCT.'USED GB  '.$USED_GB.'MAX % '.$MAX_PCT.'FREE PCT '.$FREE_PCT.'FREE GB '.$FREE_GB;


                //$parts= explode("\n", trim(`$cmd`));
                $totalg = trim(`echo $TOTAL_GB;`);
                $freeg = trim(`echo $FREE_GB;`);
                $out = "$totalg $freeg";
        }
    }
    $parts = explode(' ', $out);
    if (sizeof($parts) == 2)
    {
        $server['hdsize'] = $parts[0];
        $server['hdfree'] = $parts[1];
    }
    if (file_exists('/usr/sbin/vzctl'))
    {
        if (!file_exists('/proc/user_beancounters'))
        {
            $headers = "MIME-Version: 1.0\n";
            $headers .= "Content-type: text/html; charset=UTF-8\n";
            $headers .= "From: ".`hostname -s`." <hardware@interserver.net>\n";
            mail('hardware@interserver.net', 'OpenVZ server does not appear to be booted properly', 'This server does not have /proc/user_beancounters, was it booted into the wrong kernel?', $headers);

        }
    }
    $data = array(
        'type' => 'vps_info',
        'content' => array(
            'server' => $server,
    ));
    return $data;
};
