#!/bin/bash
if [ $# -lt 2 ]; then
	echo "Correct Syntax: $0 <id> <vzid> [image]"
	echo "ie $0 5732 windows5732 snap5732"
	echo "or $0 5732 windows5732"
	exit
fi
if [ "$(kpartx 2>&1 |grep sync)" = "" ]; then
	kpartxopts=""
else
	kpartxopts="-s"
fi
#set -x
url="https://myvps2.interserver.net/vps_queue.php"
id=$1
vzid=$2
if [ "$3" = "" ]; then
 image=snap$id
else
 image=$3
fi
cd "$(dirname $0)"
INSTDIR="$(pwd -L)"
if which virsh >/dev/null 2>&1; then
 if ! virsh dominfo $vzid >/dev/null 2>&1; then
  echo "Invalid VPS $vzid"
  curl --connect-timeout 60 --max-time 240 -k -d action=backup_status -d vps_id=${id} "$url" 2>/dev/null
  exit;
 fi
 if [ -e /${image} ]; then
 	echo "Invalid Image name - directory exists";
 	curl --connect-timeout 60 --max-time 240 -k -d action=backup_status -d vps_id=${id} "$url" 2>/dev/null
 	exit;
 fi
 /admin/swift/mkdir_p vps$id --force
 lvcreate --size 1000m --snapshot --name snap$id /dev/vz/$vzid
 sync
 mkdir -p /${image}
 $INSTDIR/vps_kvm_automount.sh snap${id} /${image}
 /admin/swift/fly vps$id /${image} delete
 /admin/swift/fly vps$id /${image}
 $INSTDIR/vps_kvm_automount.sh snap${id} /${image} unmount
 rmdir /${image}
 echo y | lvremove /dev/vz/snap${id}
else
 if ! vzlist $vzid >/dev/null 2>&1; then
  echo "Invalid VPS $vzid"
  curl --connect-timeout 60 --max-time 240 -k -d action=backup_status -d vps_id=${id} "$url" 2>/dev/null
  exit;
 fi
 if [ -e /vz/${image} ]; then
 	echo "Invalid Image name - directory exists";
 	curl --connect-timeout 60 --max-time 240 -k -d action=backup_status -d vps_id=${id} "$url" 2>/dev/null
 	exit;
 fi
 /admin/swift/mkdir_p vps$id --force
 mkdir -p /vz/${image}
 rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids --exclude=/home/virtfs /vz/root/${vzid}/ /vz/${image}
 vzctl suspend $vzid
 rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids --exclude=/home/virtfs /vz/private/${vzid}/ /vz/${image}
 vzctl resume $vzid
 cd /vz
 /admin/swift/fly vps$id ${image} delete
 /admin/swift/fly vps$id ${image}
 /bin/rm -rf /vz/${image}
fi
curl --connect-timeout 60 --max-time 240 -k -d action=backup_status -d vps_id=${id} "$url" 2>/dev/null

