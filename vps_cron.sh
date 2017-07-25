#!/bin/bash

export PATH="$PATH:/bin:/usr/bin:/usr/local/bin:/sbin:/usr/sbin:/usr/local/sbin"

if [ -e /proc/loadavg ]; then
	load_check=$(cat /proc/loadavg  | awk '{print $1}' | cut -d. -f1);
	if [ "$load_check" -gt 100 ]; then
		echo 'Load is greater than 100';
		exit;
	fi
fi

lock_file="/root/cpaneldirect/.cron.age";
daily_lock_file="/root/cpaneldirect/.cron_daily.age";



if [ -e $lock_file ]; then
        if [ "$(( $(date +"%s") - $(stat -c "%Y" $lock_file) ))" -gt "300" ]; then
                echo "$lock_file older than 300 seconds";
        else
                echo "ERROR: Lock file found, exiting";
                exit;
        fi
fi


if [ -f /dev/shm/lock ]; then
 exit;
fi

if [ "$(ps aux| grep 'php vps_cron.php' | grep -v "grep.*php" |wc -l)" = "0" ]; then
        rm -f $lock_file
	touch $lock_file

	if [ -e /proc/vz ]; then
		/root/cpaneldirect/cpu_usage_updater.sh 2>/root/cpaneldirect/cron.cpu_usage >&2 &
	fi

	php vps_cron.php >> cron.output 2>&1

	/bin/rm $lock_file

	if [ -e $daily_lock_file ]; then

		if [ "$(( $(date +"%s") - $(stat -c "%Y" $daily_lock_file) ))" -gt "86400" ]; then
			echo "$daily_lock_file older than 86400 seconds";
			/bin/rm -v $daily_lock_file
		fi

	fi

	if [ "$(ps uax|grep -e update_virtuozzo -e vps_cron_daily|grep -v grep)" = "" ]; then
		touch $daily_lock_file
		php vps_cron_daily.php >> cron.output 2>&1
		/bin/rm $daily_lock_file
	fi

fi
