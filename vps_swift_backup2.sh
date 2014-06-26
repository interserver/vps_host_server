#!/bin/bash
if [ $# -lt 2 ]; then
	echo "Correct Syntax: $0 <id> <vzid> [image]"
	echo "ie $0 5732 windows5732 snap5732"
	echo "or $0 5732 windows5732"
	exit
fi
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
 set -x
 /admin/swift/mkdir_p vps${id} --force
 sizebytes="$(lvdisplay /dev/vz/${vzid} --units B |grep "LV Size" | awk '{ print $3 }')"
 sizebuffer=10000000000
 lvcreate -L$((${sizebytes} + ${sizebuffer}))B -nimage_storage vz
 mkdir -p /vz/image_storage
 time mke2fs -q /dev/vz/image_storage
 mount /dev/vz/image_storage /vz/image_storage
 lvcreate --size 1000m --snapshot --name snap$id /dev/vz/$vzid
 sync
 time qemu-img convert -c -p -O qcow2 /dev/vz/snap${id} /vz/image_storage/${image} 
 /admin/swift/isrm vps${id} ${image}
 time /admin/swift/isput vps${id} /vz/image_storage/${image} 
 umount /vz/image_storage
 echo y | lvremove /dev/vz/image_storage
 rmdir /vz/image_storage
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
 /admin/swift/mkdir_p vps${id} --force
 mkdir -p /vz/${image}
 if [ -e /vz/private/${id}/root.hdd/root.hdd ]; then 
  UUID="$(uuidgen)"
  vzctl snapshot $id --id "$UUID" --skip-suspend --skip-config
  vzctl snapshot-mount $id --id "$UUID" --target /vz/${image}
 else
  rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids --exclude=/home/virtfs /vz/root/${vzid}/ /vz/${image}
  vzctl suspend $vzid
  rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids --exclude=/home/virtfs /vz/private/${vzid}/ /vz/${image}
  vzctl resume $vzid
 fi
 cd /vz
 /admin/swift/fly vps${id} ${image} delete
 /admin/swift/fly vps${id} ${image}
 if [ -e /vz/private/${id}/root.hdd/root.hdd ]; then
  vzctl snapshot-umount $id --id "$UUID"
  vzctl snapshot-delete $id --id "$UUID"
  rmdir /vz/${image}
 else 
  /bin/rm -rf /vz/${image}
 fi
fi
curl --connect-timeout 60 --max-time 240 -k -d action=backup_status -d vps_id=${id} "$url" 2>/dev/null

