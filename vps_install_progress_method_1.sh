#!/bin/bash
echo "$(awk '/pos:/ { print $2 }' /proc/$(pidof gzip)/fdinfo/3)/$(stat -L /proc/$(pidof gzip)/fd/3 -c "%s")*100" |bc -l|cut -d. -f1
