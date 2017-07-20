#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
display="$1"
url="$2&vnc=$1"
if [ "$#" -lt 1 ]; then
 echo "Take Screenshot Of VNC Session"
 echo " Grabs screenshot, saves as shot.jpg"
 echo "Syntax $0 [display] [url]"
 echo " ie $0 2 url.com"
else
 rm -f shot1_$1.jpg;
 function timer() {
  sleep "40" && kill "$$"
 }
 if [ -e /usr/bin/timeout ]; then
  timeout 30s /root/cpaneldirect/vncsnapshot -dieblank -compresslevel "9" \
   -quality "100" -vncQuality "9" -allowblank -count "1" -fps "5" \
   -quiet 127.0.0.1:${display} shot1_$1.jpg >/dev/null 2>&1;
 else
  timer & timerpid="$!"
  /root/cpaneldirect/vncsnapshot -dieblank -compresslevel "9" \
   -quality "100" -vncQuality "9" -allowblank -count "1" -fps "5" \
   -quiet 127.0.0.1:${display} shot1_$1.jpg >/dev/null 2>&1;
 fi;
 if [ -e shot1_$1.jpg ]; then
  convert shot1_$1.jpg -quality "75" shot_$1.gif;
  rm -f shot1_$1.jpg;
  if [ ! "$url" = "" ] && [ -e "shot_$1.gif" ]; then
   curl --connect-timeout "60" --max-time "600" -k -F screenshot=@shot_$1.gif "$url" 2>/dev/null;
  fi;
  rm -f shot_$1.gif;
 fi
 kill "$timerpid"
fi

