#!/bin/bash
if [ $# -lt 2 ]; then
	echo "Correct Syntax: $0 <id> <vzid> [image]"
	echo "ie $0 5732 windows5732 snap5732"
	echo "or $0 5732 windows5732"
	exit
fi
set -x
id=$1
vzid=$2
if [ "$3" = "" ]; then
 image=snap$id
else
 image=$3
fi
if which virsh >/dev/null 2>&1; then
 if ! virsh dominfo $vzid >/dev/null 2>&1; then
  echo "Invalid VPS $vzid"
  exit;
 fi
 if [ -e /${image} ]; then
 	echo "Invalid Image name - directory exists";
 	exit;
 fi
 /admin/swift/mkdir_p vps$id --force
 lvcreate --size 1000m --snapshot --name snap$id /dev/vz/$vzid
 mkdir -p /${image}
 kpartx -av /dev/vz/snap${id}
 if [ -e /dev/mapper/snap${id}p1 ]; then
  snap=snap
 else
  snap=vz-snap
 fi
 if [ -e /dev/mapper/${snap}${id}p6 ]; then
  mount /dev/mapper/${snap}${id}p6 /${image}
  mount /dev/mapper/${snap}${id}p1 /${image}/boot
 elif [ -e /dev/mapper/${snap}${id}p3 ]; then
  mount /dev/mapper/${snap}${id}p3 /${image}
  mount /dev/mapper/${snap}${id}p1 /${image}/boot
 elif [ -e /dev/mapper/${snap}${id}p2 ]; then
  mount /dev/mapper/${snap}${id}p2 /${image}
 else
  mount /dev/mapper/${snap}${id}p1 /${image}
 fi
 /admin/swift/isrm vps$id ${image}
 /admin/swift/fly vps$id /${image}
 if [ -e /dev/mapper/${snap}${id}p6 ]; then
  umount /${image}/boot
 fi
 if [ -e /dev/mapper/${snap}${id}p3 ]; then
  umount /${image}/boot
 fi
 umount /${image}
 kpartx -dv /dev/vz/snap$id
 rmdir /${image}
 echo y | lvremove /dev/vz/snap$id
else
 if ! vzlist $vzid >/dev/null 2>&1; then
  echo "Invalid VPS $vzid"
  exit;
 fi
 if [ -e /vz/${image} ]; then
 	echo "Invalid Image name - directory exists";
 	exit;
 fi
 /admin/swift/mkdir_p vps$id --force
 mkdir -p /vz/${image}
 rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids /vz/root/${vzid}/ /vz/${image}
 vzctl suspend $vzid
 rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids /vz/private/${vzid}/ /vz/${image}
 vzctl resume $vzid
 cd /vz
 /admin/swift/isrm vps$id ${image}
 /admin/swift/fly vps$id ${image}
 /bin/rm -rf /vz/${image}
fi
