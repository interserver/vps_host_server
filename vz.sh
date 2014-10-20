# If not running interactively, don't do anything
[ -z "$PS1" ] && return


echo
echo -n OS 'Version: '
if [ -e /etc/redhat-release ]; then
	cat /etc/redhat-release
else
	lsb_release -r
fi

echo

echo -n 'Load Av: '
cat /proc/loadavg
echo

echo -n 'Raid Status:  '
if [ -e /root/cpaneldirect/nagios-plugin-check_raid/check_raid.pl ]; then
        /root/cpaneldirect/nagios-plugin-check_raid/check_raid.pl
else
        cat /proc/mdstat
fi
echo

echo -n 'Ram'
free
echo

echo -n 'Disk Usage: '
df -h
echo
