#!/bin/bash

function age() {
   local filename="$1"
   local changed="`stat -c %Y "$filename"`"
   local now="`date +%s`"
   local elapsed

   let elapsed=now-changed
   echo "$elapsed"
}

yum upgrade -y;
echo "Disabling VPS Cron"
crontab -l > crontab.txt ;
sed s#"^\(\*.*vps_cron.*\)$"#"\#\1"#g -i crontab.txt;
crontab crontab.txt
if [ "$(ps aux|grep vps|grep -v grep)" != "" ]; then
  echo -n "Waiting for cron to stop"
  while [ "$(ps aux|grep vps|grep -v grep)" != "" ]; do
    sleep 10s;
    echo -n .;
  done
  echo "done"
fi
vzpkg update metadata;
vzpkg list -O | awk '{ print $1 }' | xargs -n "1" vzpkg fetch -O;
vzlist -a -H | awk '{ print $1 }' |xargs -n "1" vzpkg update;
if [ ! -e ".cron_weekly.age" ] || [ "$(age .cron_weekly.age)" -ge 604800 ]; then
  vzpkg update cache --update-cache;
  touch .cron_weekly.age;
fi
echo "Enabling VPS Cron"
sed s#"^\#\(\*.*vps_cron.*\)$"#"\1"#g -i crontab.txt;
crontab crontab.txt
rm -f crontab.txt
