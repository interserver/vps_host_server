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

if [ "$SHELL" = "/bin/sh" ] && [ -e /cron.vps.disabled ]; then
	exit;
fi;
if [ -f /dev/shm/lock ]; then
	exit;
fi;
export url=https://myvps2.interserver.net/vps_queue.php
export dir=/root/cpaneldirect;
export log=$dir/cron.output;
if [ $(ps aux |grep "[0-9] /bin/bash $0"|wc -l) -lt 2 ]; then
	rm -f cron.age
	touch .cron.age
	echo "[$(date "+%Y-%m-%d %H:%M:%S")] Crontab Startup" >> $log;
	if [ -e /proc/vz ]; then
		$dir/cpu_usage_updater.sh 2>$dir/cron.cpu_usage >&2 &
	fi;
	$dir/vps_update_info.php >> $log 2>&1
	curl -s --connect-timeout 60 --max-time 600 -k -d action=getnewvps $url 2>/dev/null > $dir/cron.cmd;
	if [ "$(cat $dir/cron.cmd)" != "" ]; then
		echo "Get New VPS Running:	$(cat $dir/cron.cmd)" >> $log;
		. $dir/cron.cmd >> $log 2>&1;
	fi;
	$dir/vps_traffic_new.php >> $log 2>&1
	curl -s --connect-timeout 60 --max-time 600 -k -d action=getslicemap $url 2>/dev/null > $dir/cron.cmd;
	if [ "$(cat $dir/cron.cmd)" != "" ]; then
		. $dir/cron.cmd >> $log 2>&1;
	fi;
	if [ ! -e /usr/sbin/vzctl ]; then
		curl -s --connect-timeout 60 --max-time 600 -k -d action=getipmap $url 2>/dev/null > $dir/cron.cmd;
		if [ "$(cat $dir/cron.cmd)" != "" ]; then
			. $dir/cron.cmd >> $log 2>&1;
		fi;
		curl -s --connect-timeout 60 --max-time 600 -k -d action=getvncmap $url 2>/dev/null > $dir/cron.cmd;
		if [ "$(cat $dir/cron.cmd)" != "" ]; then
			. $dir/cron.cmd >> $log 2>&1;
		fi;
	fi;
	curl -s --connect-timeout 60 --max-time 600 -k -d action=getqueue $url 2>/dev/null > $dir/cron.cmd;
	if [ "$(cat $dir/cron.cmd)" != "" ]; then
		echo "Get Queue Running:	$(cat $dir/cron.cmd)" >> $log;
		. $dir/cron.cmd >> $log 2>&1;
	fi;
	$dir/vps_get_list.php >> $log 2>&1
	if [ ! -e .cron_daily.age ] || [ $(age .cron_daily.age) -ge 86400 ]; then
		if [ "$(ps uax|grep -e update_virtuozzo -e vps_cron_daily|grep -v grep)" = "" ]; then
			touch .cron_daily.age
			php vps_cron_daily.php >> cron.output 2>&1
		fi
	fi
	/bin/rm -f $dir/cron.cmd;
else
	# kill a get list older than 2 hours
	if [ $(age .cron.age) -gt 7200 ]; then
		if [ "$(ps uax|grep vps_get_list |grep -v grep)" != "" ]; then
			kill -9 $(ps uax|grep vps_get_list |grep -v grep | awk '{ print $2 }')
		fi
	fi
fi
