#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin";
set -x;
if [ "$(kpartx 2>&1 |grep sync)" = "" ]; then
	kpartxopts="";
else
	kpartxopts="-s";
fi;
name=$1;
if [ $# -ne 1 ]; then
 echo "Clear VPS Password";
 echo "Syntax $0 [name] [pass]";
 echo " ie $0 windows1337";
#check if vps exists
elif ! virsh dominfo ${name} >/dev/null 2>&1; then
 echo "VPS ${name} doesnt exists!";
else
 virsh destroy ${name};
 echo "Creating Partition Table Links" && \
 /sbin/kpartx $kpartxopts -av /dev/vz/${name} && \
 if [ -e "/dev/mapper/vz-${name}p1" ]; then
  pname="vz-${name}";
 else
  pname="$name";
 fi && \
 mkdir -p /vz/mounts/${pname}p2 && \
 echo "Mounting Partition";
 if [ -e /dev/mapper/${pname}p2 ]; then
  mount /dev/mapper/${pname}p2 /vz/mounts/${pname}p2;
 else
  mount /dev/mapper/${pname}p1 /vz/mounts/${pname}p2;
 fi;
 if [ -e /vz/mounts/${pname}p2/Windows/winsxs/pending.xml ]; then
  echo "Removing Pending Updates File";
  rm -f "/vz/mounts/${pname}p2/Windows/winsxs/pending.xml";
 fi;
 echo "Clearing Password";
 /root/cpaneldirect/sampasswd -r -u Administrator -v /vz/mounts/${pname}p2/Windows/System32/config/SAM;
 echo "Saving Changes";
 sync;
 sleep 2s;
 umount /vz/mounts/${pname}p2 2>/dev/null;
 sync;
 sleep 1s;
 /sbin/kpartx $kpartxopts -dv /dev/vz/${name};
 echo "Starting VPS";
 virsh start ${name};
 /root/cpaneldirect/vps_refresh_vnc.sh $name;
fi
