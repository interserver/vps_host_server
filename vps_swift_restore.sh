#!/bin/bash
if [ $# -lt 3 ]; then
  echo "Invalid Parameters"
  echo "Correct Syntax: $0 <source vps id> <backup name> <destination #1 vps vzid> [destination #2 vps vzid] ..."
  echo "Example: $0 5732 windows9044 snap9044 windows9055 windows9066 windows9077"
  exit
fi
set -x
if [ "$(kpartx 2>&1 |grep sync)" = "" ]; then
	kpartxopts=""
else
	kpartxopts="-s"
fi
sourceid="$1"
shift
image="$1"
shift
destids="$*"
if [ "$(/admin/swift/isls vps${sourceid} |grep "^${image}/")" = "" ]; then
	echo "Backup does not exist"
	exit
fi
if which virsh >/dev/null 2>&1; then
  cd /
  if [ -e /${image} ]; then
  	echo "Invalid Image name - directory exists";
  	exit;
  fi
  #if [ $# -gt 1 ]; then
	/admin/swift/isget vps${sourceid} ${image}
	mv ${image} /${image}.tar.gz
  #fi
  mkdir -p /${image}
else
  if [ -e /vz/${image} ]; then
  	echo "Invalid Image name - directory exists";
  	exit;
  fi
  cd /vz
  /admin/swift/isget vps${sourceid} ${image} -out | tar xzf -
fi
for i in $destids; do
  if which virsh >/dev/null 2>&1; then
	if ! virsh dominfo $i >/dev/null 2>&1; then
	  echo "Invalid VPS $i"
	else
	  echo "working on $i";
	  virsh destroy $i
	  kpartx $kpartxopts -av /dev/vz/$i
      if [ -e /dev/mapper/${i}p1 ]; then
       mapdir=$i
      else
       mapdir=vz-$i
      fi
	  if [ -e /dev/mapper/${mapdir}p6 ]; then
		mount -o rw /dev/mapper/${mapdir}p6 /${image} || exit
		mount -o rw /dev/mapper/${mapdir}p1 /${image}/boot || exit
	  elif [ -e /dev/mapper/${mapdir}p3 ]; then
		mount -o rw /dev/mapper/${mapdir}p3 /${image} || exit
		mount -o rw /dev/mapper/${mapdir}p1 /${image}/boot || exit
	  elif [ -e /dev/mapper/${mapdir}p2 ]; then
		mount -o rw /dev/mapper/${mapdir}p2 /${image} || exit
	  else
		mount -o rw /dev/mapper/${mapdir}p1 /${image} || exit
	  fi
	  /bin/rm -rf /${image}/* 2>/dev/null
	  #if [ $# -gt 1 ]; then
		tar xzf /${image}.tar.gz
	  #else
		#/admin/swift/isget vps${sourceid} ${image} -out | tar xzf -
	  #fi
	  if [ -e /dev/mapper/${mapdir}p6 ]; then
		umount /${image}/boot
	  elif [ -e /dev/mapper/${mapdir}p3 ]; then
		umount /${image}/boot
	  fi
	  umount /${image}
	  kpartx $kpartxopts -dv /dev/vz/$i
	  virsh start $i
	fi
  else
	if ! vzlist $i >/dev/null 2>&1; then
	  echo "Invalid VPS $i"
	else
	  echo "working on $i"
	  vzctl stop $i
	  rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids /vz/${image}/ /vz/private/$i
	  vzctl start $i
	fi
  fi
done
if which virsh >/dev/null 2>&1; then
  /bin/rmdir /${image}
  if [ $# -gt 1 ]; then
	rm -f /${image}.tar.gz
  fi
else
  /bin/rm -rf /vz/${image}
fi
