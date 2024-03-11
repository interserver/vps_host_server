#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin";
export base="$(readlink -f "$(dirname "$0")")";
export TERM=linux;
display=$1;
vps=$2;
if [ ! -e /root/.swift/config ]; then
 echo no swift config file
 exit
fi
if [ $# -lt 1 ]; then
 echo "Take Screenshot Of VNC Session"
 echo " Grabs screenshot, saves as shot.jpg"
 echo "Syntax $0 [display] [url]"
 echo " ie $0 2 url.com"
else
 function timer() {
  sleep 40 && kill $$
 }
 ifile="reset_shot_${vps}_$(date +%Y%m%d).jpg";
 rm -f shot_$1.jpg shot1_$1.jpg;
 if [ -e /usr/bin/timeout ]; then
  timeout 30s ${base}/vncsnapshot -dieblank -compresslevel 9 \
   -quality 100 -vncQuality 9 -allowblank -count 1 -fps 5 \
   -quiet 127.0.0.1:$display "$ifile" >/dev/null 2>&1;
 else
  timer & timerpid=$!
  ${base}/vncsnapshot -dieblank -compresslevel 9 \
   -quality 100 -vncQuality 9 -allowblank -count 1 -fps 5 \
   -quiet 127.0.0.1:$display "$ifile" >/dev/null 2>&1;
 fi;
 /admin/swift/c ismkdir vps${vps};
 /admin/swift/c isput vps${vps} "$ifile";
 /bin/rm -f "$ifile";
 if [ ! -z "$timerpid" ]; then
  kill "$timerpid";
 fi;
fi;
