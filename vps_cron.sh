#!/bin/bash
export PATH="$PATH:/bin:/usr/bin:/usr/local/bin:/sbin:/usr/sbin:/usr/local/sbin"

function age() {
   local filename=$1
   local changed=`stat -c %Y "$filename"`
   local now=`date +%s`
   local elapsed

   let elapsed=now-changed
   echo $elapsed
}

if [ -f /dev/shm/lock ]; then
 exit;
fi
if [ "$(ps aux| grep 'php vps_cron.php' | grep -v "grep.*php" |wc -l)" = "0" ]; then
        rm -f cron.age
	touch .cron.age
	if [ -e /proc/vz ]; then
		/root/cpaneldirect/cpu_usage_updater.sh 2>/root/cpaneldirect/cron.cpu_usage >&2 &
	fi;
	php vps_cron.php >> cron.output 2>&1
	if [ ! -e .cron_daily.age ] || [ $(age .cron_daily.age) -ge 86400 ]; then
		if [ "$(ps uax|grep -e update_virtuozzo -e vps_cron_daily|grep -v grep)" = "" ]; then
			touch .cron_daily.age
			php vps_cron_daily.php >> cron.output 2>&1
		fi
	fi
else
	# kill a get list older than 2 hours
	if [ $(age .cron.age) -gt 7200 ]; then
		if [ "$(ps uax|grep vps_get_list |grep -v grep)" != "" ]; then
			kill -9 $(ps uax|grep vps_get_list |grep -v grep | awk '{ print $2 }')
		fi
	fi
fi
