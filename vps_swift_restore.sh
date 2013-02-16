#!/bin/bash
if [ $# -lt 2 ]; then
  echo "Invalid Parameters"
  echo "Correct Syntax: $0 <source vps id> <destination #1 vps vzid> [destination #2 vps vzid] ..."
  echo "Example: $0 5732 windows9044 windows9055 windows9066 windows9077"
  exit
fi
set -x
sourceid="$1"
shift
destids="$*"
if [ "$(/admin/swift/isls vps${sourceid} |grep "^snap${sourceid}/")" = "" ]; then
	echo "Backup does not exist"
	exit
fi
if which virsh >/dev/null 2>&1; then
  cd /
  mkdir -p /snap${sourceid}
  if [ $# -gt 1 ]; then
	/admin/swift/isget vps${sourceid} snap${sourceid}
	mv snap${sourceid} /snap${sourceid}.tar.gz
  fi
else
  cd /vz
  /admin/swift/isget vps${sourceid} snap${sourceid} -out | tar xzf -
fi
for i in $destids; do
  if which virsh >/dev/null 2>&1; then
	if ! virsh dominfo $i >/dev/null 2>&1; then
	  echo "Invalid VPS $i"
	else
	  echo "working on $i";
	  virsh destroy $i
	  kpartx -av /dev/vz/$i
      if [ -e /dev/mapper/${i}p1 ]; then
       mapdir=$i
      else
       mapdir=vz-$i
      fi
	  if [ -e /dev/mapper/${mapdir}p3 ]; then
		mount -o rw /dev/mapper/${mapdir}p3 /snap${sourceid} || exit
		mount -o rw /dev/mapper/${mapdir}p1 /snap${sourceid}/boot || exit
	  elif [ -e /dev/mapper/${mapdir}p2 ]; then
		mount -o rw /dev/mapper/${mapdir}p2 /snap${sourceid} || exit
	  else
		mount -o rw /dev/mapper/${mapdir}p1 /snap${sourceid} || exit
	  fi
	  /bin/rm -rf /snap${sourceid}/* 2>/dev/null
	  if [ $# -gt 1 ]; then
		tar xzf /snap${sourceid}.tar.gz
	  else
		/admin/swift/isget vps${sourceid} snap${sourceid} | tar xzf -
	  fi
	  if [ -e /dev/mapper/${mapdir}p3 ]; then
		umount /snap${sourceid}/boot
	  fi
	  umount /snap${sourceid}
	  kpartx -dv /dev/vz/$i
	  virsh start $i
	fi
  else
	if ! vzlist $i >/dev/null 2>&1; then
	  echo "Invalid VPS $i"
	else
	  echo "working on $i"
	  vzctl stop $i
	  rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids /vz/snap${sourceid}/ /vz/private/$i
	  vzctl start $i
	fi
  fi
done
if which virsh >/dev/null 2>&1; then
  /bin/rmdir /snap${sourceid}
  if [ $# -gt 1 ]; then
	rm -f /snap${sourceid}.tar.gz
  fi
else
  /bin/rm -rf /vz/snap${sourceid}
fi
