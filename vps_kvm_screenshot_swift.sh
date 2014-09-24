#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
display=$1
vps=$2
if [ $# -lt 1 ]; then
 echo "Take Screenshot Of VNC Session"
 echo " Grabs screenshot, saves as shot.jpg"
 echo "Syntax $0 [display] [url]"
 echo " ie $0 2 url.com"
else
 function timer() {
  sleep 40 && kill $$
 }
 timer & timerpid=$!
 ifile="reset_shot_${vps}_$(date +%Y%m%d).jpg";
 rm -f shot_$1.jpg shot1_$1.jpg;
 /root/cpaneldirect/vncsnapshot -dieblank -compresslevel 9 \
 -quality 100 -vncQuality 9 -allowblank -count 1 -fps 5 \
 -quiet 127.0.0.1:$display "$ifile" >/dev/null 2>&1;
 makedir vps${vps}; 
 upload vps${vps} "$ifile"; 
 /bin/rm -f "$ifile";
 kill "$timerpid"
fi

