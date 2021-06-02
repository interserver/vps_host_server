#!/bin/bash

export HOME=/root
export PATH=/sbin:/bin:/usr/sbin:/usr/bin:/usr/games:/usr/local/sbin:/usr/local/bin:/usr/X11R6/bin


function age() {
   local filename=$1
   local changed=`stat -c %Y "$filename"`
   local now=`date +%s`
   local elapsed

   let elapsed=now-changed
   echo $elapsed
}

#yum upgrade -y;
/usr/bin/hostname && /usr/bin/hsotname -i
if [ "$(which vzpkg)" = "" ]; then
  echo "Cannot find vzpkg package for update_virtuozzo.sh script on $HOSTNAME" | mail support@interserver.net
else
  vzpkg update metadata;
  vzpkg list -O | awk '{ print $1 }' | xargs -n 1 vzpkg fetch -O;
  vzlist -a -H | awk '{ print $1 }' |xargs -n 1 vzpkg update;
  if [ ! -e ".cron_weekly.age" ] || [ $(age .cron_weekly.age) -ge 604800 ]; then
	vzpkg update cache --update-cache;
	touch .cron_weekly.age;
  fi
fi