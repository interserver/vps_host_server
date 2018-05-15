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

export pid=$$
if [ "$(ps aux |grep "[0-9] /bin/bash $0"|awk '{ print $2 }'|grep -v "^${pid}$")" = "" ]; then
	php qs_cron.php >> cron.output 2>&1
else
	# kill a get list older than 2 hours
	if [ $(age cron.age) -gt 7200 ]; then
		if [ "$(ps uax|grep qs_get_list |grep -v grep)" != "" ]; then
			kill -9 $(ps uax|grep qs_get_list |grep -v grep | awk '{ print $2 }')
		fi
	fi
fi
