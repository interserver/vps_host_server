#!/bin/bash
port="$1"
touch shot_${port}.started
/root/cpaneldirect/vncsnapshot -compresslevel 9 -quality 100 -vncQuality 9 -allowblank -count 1 -fps 5 -quiet 127.0.0.1:$(($port - 5900)) shot_${port}.jpg >/dev/null 2>&1
convert shot_${port}.jpg -quality 75 shot_${port}.gif
rm -f shot_${port}.jpg shot_${port}.started

