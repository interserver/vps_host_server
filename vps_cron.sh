#!/bin/bash
export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/bin:/usr/bin:/sbin:/usr/sbin"
export base="$(readlink -f "$(dirname "$0")")";
export url=https://myvps.interserver.net/vps_queue.php
export dir=${base};
export log=$dir/cron.output;
export old_cron=1;

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
if [ -e $dir/.enable_workerman ]; then
	export old_cron=0;
	if [ $($dir/workerman/start.php status 2>/dev/null|grep "PROCESS STATUS"|wc -l) -eq 0 ]; then
		$dir/workerman/update.sh >> $log 2>&1;
		$dir/workerman/start.php restart -d >> $log 2>&1;
	fi;
	sleep 2s;
	if [ $($dir/workerman/start.php status 2>/dev/null|grep "PROCESS STATUS"|wc -l) -eq 0 ]; then
		echo "$HOSTNAME cannot start workerman" | mail detain@interserver.net
		export old_cron=1;
	fi;
fi
if [ $old_cron -eq 1 ]; then
	export pslog=$dir/cron.psoutput;
	ps ux |grep "/bin/bash $0"|grep -v -e grep -e " $(($$ + 1)) " > $pslog
	count=$(cat $pslog|wc -l)
	if [ $count -ge 2 ]; then
		echo "Got count $count" >> $log
		cat $pslog >> $log;
		# kill a get list older than 2 hours
		if [ $(age .cron.age) -gt 7200 ]; then
			if [ "$(ps uax|grep "cron vps-info" |grep -v grep)" != "" ]; then
			#if [ "$(ps uax|grep vps_get_list |grep -v grep)" != "" ]; then
				#kill -9 $(ps uax|grep vps_get_list |grep -v grep | awk '{ print $2 }')
				kill -9 $(ps uax|grep "cron vps-info" |grep -v grep | awk '{ print $2 }')
			fi
		fi
	else
		rm -f cron.age
		touch .cron.age
		echo "[$(date "+%Y-%m-%d %H:%M:%S")] Crontab Startup" >> $log;
		if [ -e /proc/vz ]; then
			#$dir/cpu_usage_updater.sh 2>$dir/cron.cpu_usage >&2 &
			$dir/provirted.phar cron cpu-usage 2>$dir/cron.cpu_usage >&2 &
		fi;
		#$dir/vps_update_info.php >> $log 2>&1
		$dir/provirted.phar cron host-info >> $log 2>&1
		#curl -s --connect-timeout 60 --max-time 600 -k -d action=get_new_vps $url 2>/dev/null > $dir/cron.cmd;
        curl -s --connect-timeout 60 --max-time 600 -k -d action=get_new_vps http://myvps.interserver.net:55151/queue.php 2>/dev/null > $dir/cron.cmd;
		if [ "$(cat $dir/cron.cmd)" != "" ] && [ "$(grep "Session halted." $dir/cron.cmd)" = "" ]; then
			echo "Get New VPS Running:	$(cat $dir/cron.cmd)" >> $log;
			. $dir/cron.cmd >> $log 2>&1;
		fi;
		#$dir/vps_traffic_new.php vps >> $log 2>&1
		$dir/provirted.phar cron bw-info >> $log 2>&1
		curl -s --connect-timeout 10 --max-time 15 -d action=map http://myvps.interserver.net:55151/queue.php | bash
		#curl -s --connect-timeout 60 --max-time 600 -k -d action=get_queue $url 2>/dev/null > $dir/cron.cmd;
        curl -s --connect-timeout 60 --max-time 600 -k -d action=get_queue http://myvps.interserver.net:55151/queue.php 2>/dev/null > $dir/cron.cmd;
		if [ "$(cat $dir/cron.cmd)" != "" ] && [ "$(grep "Session halted." $dir/cron.cmd)" = "" ]; then
			echo "Get Queue Running:	$(cat $dir/cron.cmd)" >> $log;
			. $dir/cron.cmd >> $log 2>&1;
		fi;
		#$dir/vps_get_list.php >> $log 2>&1
		$dir/provirted.phar cron vps-info >> $log 2>&1
#		if [ ! -e .cron_daily.age ] || [ $(age .cron_daily.age) -ge 86400 ]; then
#			if [ "$(ps uax|grep -e update_virtuozzo -e vps_cron_daily|grep -v grep)" = "" ]; then
#				touch .cron_daily.age
#				php vps_cron_daily.php >> cron.output 2>&1
#			fi
#		fi
		/bin/rm -f $dir/cron.cmd;
	fi
	rm -f $pslog
fi;
