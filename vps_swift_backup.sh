#!/bin/bash
if [ $# -lt 1 ]; then
	echo "Correct Syntax: $0 <id> <vzid>"
	echo "ie $0 5732 windows5732"
	exit
fi
set -x
id=$1
vzid=$2
if which virsh >/dev/null 2>&1; then
 if ! virsh dominfo $vzid >/dev/null 2>&1; then
  echo "Invalid VPS $vzid"
  exit;
 fi
 /admin/swift/mkdir_p vps$id --force
 lvcreate --size 1000m --snapshot --name snap$id /dev/vz/$vzid
 mkdir -p /snap$id
 kpartx -av /dev/vz/snap${id}
 if [ -e /dev/mapper/snap${id}p1 ]; then
  snap=snap
 else
  snap=vz-snap
 fi
 if [ -e /dev/mapper/${snap}${id}p3 ]; then
  mount /dev/mapper/${snap}${id}p3 /snap$id
  mount /dev/mapper/${snap}${id}p1 /snap${id}/boot
 elif [ -e /dev/mapper/${snap}${id}p2 ]; then
  mount /dev/mapper/${snap}${id}p2 /snap$id
 else
  mount /dev/mapper/${snap}${id}p1 /snap$id
 fi
 /admin/swift/fly vps$id /snap$id
 if [ -e /dev/mapper/${snap}${id}p3 ]; then
  umount /snap$id/boot
 fi
 umount /snap$id
 kpartx -dv /dev/vz/snap$id
 rmdir /snap$id
 echo y | lvremove /dev/vz/snap$id
else
 if ! vzlist $vzid >/dev/null 2>&1; then
  echo "Invalid VPS $vzid"
  exit;
 fi
 /admin/swift/mkdir_p vps$id --force
 mkdir -p /vz/snap$id
 rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids /vz/root/${vzid}/ /vz/snap${id}
 vzctl suspend $vzid
 rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids /vz/private/${vzid}/ /vz/snap${id}
 vzctl resume $vzid
 cd /vz
 /admin/swift/fly vps$id snap$id
 /bin/rm -rf /vz/snap${id}
fi
