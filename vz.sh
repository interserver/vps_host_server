## If not running interactively, don't do anything
#export base="$(readlink -f "$(dirname "$0")")";
#[ -z "$PS1" ] && return


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
if [ -e ${base}/nagios-plugin-check_raid/check_raid.sh ]; then
		${base}/nagios-plugin-check_raid/check_raid.sh --check=WARNING
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

if [ -x /usr/bin/kcarectl ]; then
	/usr/bin/kcarectl -u
elif [ -x /usr/sbin/uptrack-upgrade ]; then
	/usr/sbin/uptrack-upgrade -y
else
	echo 'No rebootless kernel update installed';
fi

export VISUAL=nano
