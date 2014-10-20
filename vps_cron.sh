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

if [ "$(ps aux| grep 'php vps_cron.php' | grep -v "grep php" |wc -l)" = "0" ]; then
	touch cron.age
	/root/cpaneldirect/cpu_usage_updater.sh >&2  2>/root/cpaneldirect/cron.cpu_usage &
	php vps_cron.php >> cron.output 2>&1
else
	# kill a get list older than 2 hours
	if [ $(age cron.age) -gt 7200 ]; then
		if [ "$(ps uax|grep vps_get_list |grep -v grep)" != "" ]; then
			kill -9 $(ps uax|grep vps_get_list |grep -v grep | awk '{ print $2 }')
		fi
	fi
fi
