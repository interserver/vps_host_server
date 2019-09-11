#!/bin/bash
export base="$(readlink -f "$(dirname "$0")")";
if [ $# -lt 3 ]; then
  echo "Invalid Parameters"
  echo "Correct Syntax: $0 <source vps id> <backup name> <destination #1 vps vzid> [destination #2 vps vzid] ..."
  echo "Example: $0 5732 windows9044 snap9044 windows9055 windows9066 windows9077"
  exit
fi
export TERM=linux;
#set -x
url="https://mynew.interserver.net/vps_queue.php"
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
export TERM=linux;
if [ -e /etc/redhat-release ] && [ $(cat /etc/redhat-release| cut -d" " -f3 | cut -d"." -f1) -le 6 ]; then
	if [ $(echo "$(e2fsck -V 2>&1 |head -n 1 | cut -d" " -f2 | cut -d"." -f1-2) * 100" | bc | cut -d"." -f1) -le 141 ]; then
		if [ ! -e /opt/e2fsprogs/sbin/e2fsck ]; then
			pushd $PWD;
			cd /admin/ports
			./install e2fsprogs
			popd;
		fi;
		export PATH="/opt/e2fsprogs/sbin:$PATH";
	fi;
fi;
if [ "$(/admin/swift/c isls vps${sourceid} |grep "^${image}/")" = "" ]; then
	echo "Backup does not exist"
	curl --connect-timeout 60 --max-time 600 -k -d action=restore_status -d vps_id=${id} "$url" 2>/dev/null
	exit
fi
if which virsh >/dev/null 2>&1; then
  cd /
  if [ -e /${image} ]; then
	echo "Invalid Image name - directory exists";
	curl --connect-timeout 60 --max-time 600 -k -d action=restore_status -d vps_id=${id} "$url" 2>/dev/null
	exit;
  fi
  #if [ $# -gt 1 ]; then
	trap "/admin/swift/c isget vps${sourceid} ${image} -c;" SIGHUP
	/admin/swift/c isget vps${sourceid} ${image}
	trap - SIGHUP
	mv ${image} /${image}.tar.gz
  #fi
  mkdir -p /${image}
else
  if [ -e /vz/${image} ]; then
	echo "Invalid Image name - directory exists";
	curl --connect-timeout 60 --max-time 600 -k -d action=restore_status -d vps_id=${id} "$url" 2>/dev/null
	exit;
  fi
  cd /vz
  /admin/swift/c isget vps${sourceid} ${image} -out | tar xzpf - 2>/dev/null
fi
for i in $destids; do
  if which virsh >/dev/null 2>&1; then
	if ! virsh dominfo $i >/dev/null 2>&1; then
	  echo "Invalid VPS $i"
	else
	  echo "working on $i";
	  virsh destroy $i 2>/dev/null
	  success=0
	  if which guestmount >/dev/null 2>/dev/null; then
	   guestmount -d $i -i --rw /${image} 2>/dev/null && success=1;
	  fi;
	  if [ $success -eq 0 ]; then
	   kpartx $kpartxopts -av /dev/vz/$i
	   if [ -e /dev/mapper/${i}p1 ]; then
		mapdir=$i
	   else
		mapdir=vz-$i
	   fi
	   if [ -e /dev/mapper/${mapdir}p6 ]; then
		 fsck -T -p /dev/mapper/${mapdir}p6 2>/dev/null || ntfsfix /dev/mapper/${mapdir}p6
		 mount -o rw /dev/mapper/${mapdir}p6 /${image} || exit
		 fsck -T -p /dev/mapper/${mapdir}p1 2>/dev/null || ntfsfix /dev/mapper/${mapdir}p1
		 mount -o rw /dev/mapper/${mapdir}p1 /${image}/boot || exit
	   elif [ -e /dev/mapper/${mapdir}p3 ]; then
		 fsck -T -p /dev/mapper/${mapdir}p3 2>/dev/null || ntfsfix /dev/mapper/${mapdir}p1
		 mount -o rw /dev/mapper/${mapdir}p3 /${image} || exit
		 fsck -T -p /dev/mapper/${mapdir}p1 2>/dev/null || ntfsfix /dev/mapper/${mapdir}p1
		 mount -o rw /dev/mapper/${mapdir}p1 /${image}/boot || exit
	   elif [ -e /dev/mapper/${mapdir}p2 ]; then
		 fsck -T -p /dev/mapper/${mapdir}p2 2>/dev/null || ntfsfix /dev/mapper/${mapdir}p2
		 mount -o rw /dev/mapper/${mapdir}p2 /${image} || exit
	   else
		 fsck -T -p /dev/mapper/${mapdir}p1 2>/dev/null || ntfsfix /dev/mapper/${mapdir}p1
		 mount -o rw /dev/mapper/${mapdir}p1 /${image} || exit
	   fi
	  fi
	  /bin/rm -rf /${image}/* 2>/dev/null
	  #if [ $# -gt 1 ]; then
		#tar xzpf /${image}.tar.gz
		#zcat -c /${image}.tar.gz |tar --ignore-failed-read --atime-preserve --preserve-permissions -x -p -f - 2>/dev/null || export error=1
		tar --ignore-failed-read --atime-preserve --preserve-permissions -x -z -p -f /${image}.tar.gz 2>/dev/null || export error=1
	  #else
		#/admin/swift/c isget vps${sourceid} ${image} -out | tar xzpf -
	  #fi
	  sync
	  sleep 5s;
	  if which guestunmount >/dev/null 2>/dev/null; then
	   guestunmount /${image} 2>/dev/null || fusermount -u /${image}
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
		  bash ${base}/run_buildebtables.sh;
	  ${base}/vps_refresh_vnc.sh $i
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
curl --connect-timeout 60 --max-time 600 -k -d action=restore_status -d vps_id=${id} "$url" 2>/dev/null
#set -x
if which virsh >/dev/null 2>&1; then
  for i in $(ls /dev/mapper/*p[0-9] 2>/dev/null | sed s#"/dev/mapper/vz-"#""#g | sed s#"/dev/mapper/"#""#g | sed s#"p[0-9]$"#""#g); do
   kpartx $kpartxopts -dv /dev/vz/$i
  done
  /bin/rm -rf /${image}
  /bin/rm -rf /${image}.tar.gz
else
  /bin/rm -rf /vz/${image}
fi
