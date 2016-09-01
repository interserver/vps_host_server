#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
name=$1
size=$2
IFS="
"
if [ $# -ne 2 ]; then
 echo "Create a New LVM (non destructivly)"
 echo "Syntax $0 [name] [size]"
 echo " ie $0 windows1337 101000"
#check if vps exists
else
 echo "Creating LVM ${name}" 
 if [ "$(lvdisplay  |grep 'Allocated pool')" = "" ]; then
   thin="no"
 else
   thin="yes"
 fi
 if [ "$size" = "all" ]; then
  if [  "$(lvdisplay /dev/vz/$name)" = "" ]; then
   #lvcreate -l +100%FREE -n${name} vz
   lvcreate -l $(echo "($(pvdisplay -c | cut -d: -f8,10| tr : "*"))-(1024*1024*4)"|bc)k -n${name} vz
  else
   echo "already exists, extending to 100%"
   #lvextend -l +100%FREE /dev/vz/$name
   lvextend -l +$(echo "($(pvdisplay -c | cut -d: -f8,10| tr : "*"))-(1024*1024*4)"|bc)k /dev/vz/$name
  fi
 elif [ "$(lvdisplay /dev/vz/$name | grep "LV Size.*"$(echo "$size / 1024" | bc -l | cut -d\. -f1))" = "" ]; then
  if [ "$thin" = "yes" ]; then
   lvcreate -V${size} -T vz/thin -n${name} 
  else
   lvcreate -L${size} -n${name} vz
  fi
 else
  echo "already exists, skipping"
 fi
fi
