#!/bin/bash
if [ $# -lt 3 ]; then
  echo "Invalid Parameters"
  echo "Correct Syntax: $0 <source vps id> <backup name> <destination #1 vps vzid> [destination #2 vps vzid] ..."
  echo "Example: $0 5732 windows9044 snap9044 windows9055 windows9066 windows9077"
  exit fi export TERM=linux; o#set -x url="https://myvps2.interserver.net/vps_queue.php" if [ "$(kpartx 2>&1 |grep sync)" = "" ]; then
	kpartxopts=""
else
	kpartxopts="-s"
fi
sourceid="$1"
shift
image="$1"
shift
destids="$*"
export TERM=linux;
if [ "$(/root/cpaneldirect/qswift isls vps${sourceid} |grep "^${image}/")" = "" ]; then
	echo "Backup does not exist"
	curl --connect-timeout 60 --max-time 240 -k -d action=restore_status -d vps_id=${id} "$url" 2>/dev/null
	exit
fi
if which virsh >/dev/null 2>&1; then
  cd /
  if [ -e /${image} ]; then
	echo "Invalid Image name - directory exists";
	curl --connect-timeout 60 --max-time 240 -k -d action=restore_status -d vps_id=${id} "$url" 2>/dev/null
	exit;
  fi
  #if [ $# -gt 1 ]; then
	trap "/root/cpaneldirect/qswift isget vps${sourceid} ${image} -c;" SIGHUP
	/root/cpaneldirect/qswift isget vps${sourceid} ${image}
	trap - SIGHUP
	mv ${image} /${image}.tar.gz
  #fi
  mkdir -p /${image}
else
  if [ -e /vz/${image} ]; then
	echo "Invalid Image name - directory exists";
	curl --connect-timeout 60 --max-time 240 -k -d action=restore_status -d vps_id=${id} "$url" 2>/dev/null
	exit;
  fi
  cd /vz
  /root/cpaneldirect/qswift isget vps${sourceid} ${image} -out | tar xzf - 2>/dev/null
fi
for i in $destids; do
  if which virsh >/dev/null 2>&1; then
	if ! virsh dominfo $i >/dev/null 2>&1; then
	  echo "Invalid VPS $i"
	else
	  echo "working on $i";
	  virsh destroy $i
	  if which guestmount >/dev/null 2>/dev/null; then
	   guestmount -d $i -i --rw /${image}
	  else
	   kpartx $kpartxopts -av /dev/vz/$i
	   if [ -e /dev/mapper/${i}p1 ]; then
		mapdir=$i
	   else
		mapdir=vz-$i
	   fi
	   if [ -e /dev/mapper/${mapdir}p6 ]; then
		 fsck -T -p /dev/mapper/${mapdir}p6 || ntfsfix /dev/mapper/${mapdir}p6
		 mount -o rw /dev/mapper/${mapdir}p6 /${image} || exit
		 fsck -T -p /dev/mapper/${mapdir}p1 || ntfsfix /dev/mapper/${mapdir}p1
		 mount -o rw /dev/mapper/${mapdir}p1 /${image}/boot || exit
	   elif [ -e /dev/mapper/${mapdir}p3 ]; then
		 fsck -T -p /dev/mapper/${mapdir}p3 || ntfsfix /dev/mapper/${mapdir}p1
		 mount -o rw /dev/mapper/${mapdir}p3 /${image} || exit
		 fsck -T -p /dev/mapper/${mapdir}p1 || ntfsfix /dev/mapper/${mapdir}p1
		 mount -o rw /dev/mapper/${mapdir}p1 /${image}/boot || exit
	   elif [ -e /dev/mapper/${mapdir}p2 ]; then
		 fsck -T -p /dev/mapper/${mapdir}p2 || ntfsfix /dev/mapper/${mapdir}p2
		 mount -o rw /dev/mapper/${mapdir}p2 /${image} || exit
	   else
		 fsck -T -p /dev/mapper/${mapdir}p1 || ntfsfix /dev/mapper/${mapdir}p1
		 mount -o rw /dev/mapper/${mapdir}p1 /${image} || exit
	   fi
	  fi
	  /bin/rm -rf /${image}/* 2>/dev/null
	  #if [ $# -gt 1 ]; then
		#tar xzf /${image}.tar.gz
		zcat -c /${image}.tar.gz |tar --ignore-failed-read --atime-preserve --preserve-permissions -x -f - 2>/dev/null
	  #else
		#/root/cpaneldirect/qswift isget vps${sourceid} ${image} -out | tar xzf -
	  #fi
	  sync
	  sleep 5s;
	  if which guestunmount >/dev/null 2>/dev/null; then
	   guestunmount /${image} || fusermount -u /${image}
	  elif which guestmount >/dev/null 2>/dev/null; then 
	   fusermount -u /${image}
	  else
	   if [ -e /dev/mapper/${mapdir}p6 ]; then
		 umount /${image}/boot
	   elif [ -e /dev/mapper/${mapdir}p3 ]; then
		 umount /${image}/boot
	   fi
	   umount /${image}
	   kpartx $kpartxopts -dv /dev/vz/$i
	  fi
	  sync
	  sleep 5s;
	  virsh start $i
	  /root/cpaneldirect/vps_refresh_vnc.sh $i
	fi
  else
	if ! vzlist $i >/dev/null 2>&1; then
	  echo "Invalid VPS $i"
	else
	  echo "working on $i"
	  vzctl stop $i
	  vzctl mount $i
	  rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids /vz/${image}/ /vz/root/$i
	  vzctl umount $i
	  vzctl start $i
	fi
  fi
done
curl --connect-timeout 60 --max-time 240 -k -d action=restore_status -d vps_id=${id} "$url" 2>/dev/null
#set -x
if which virsh >/dev/null 2>&1; then
  for i in $(ls /dev/mapper/*p[0-9] | sed s#"/dev/mapper/vz-"#""#g | sed s#"/dev/mapper/"#""#g | sed s#"p[0-9]$"#""#g); do
   kpartx $kpartxopts -dv /dev/vz/$i
  done
  /bin/rm -rf /${image}
  /bin/rm -rf /${image}.tar.gz
else
  /bin/rm -rf /vz/${image}
fi
