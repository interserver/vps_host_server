#!/bin/bash
if [ $# -lt 2 ]; then
	echo "Correct Syntax: $0 <id> <vzid> [image]"
	echo "ie $0 5732 windows5732 snap5732"
	echo "or $0 5732 windows5732"
	exit
fi
export TERM=linux;
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
	/root/cpaneldirect/qswift mkdir_p vps${id} --force
	lvcreate --size 10000m --snapshot --name snap$id /dev/vz/$vzid
	sync
	mkdir -p /${image}
	if which guestmount >/dev/null 2>/dev/null; then 
		guestmount -d $vzid -i --ro /${image}
	else
		$INSTDIR/vps_kvm_automount.sh snap${id} /${image} readonly
	fi
	/root/cpaneldirect/qswift fly vps${id} /${image} delete
	/root/cpaneldirect/qswift fly vps${id} /${image}
	if which guestunmount >/dev/null 2>/dev/null; then 
		guestunmount /${image} || fusermount -u /${image}
	elif which guestmount >/dev/null 2>/dev/null; then 
		fusermount -u /${image}
	else
		$INSTDIR/vps_kvm_automount.sh snap${id} /${image} unmount
	fi
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
	/root/cpaneldirect/qswift mkdir_p vps${id} --force
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
	/root/cpaneldirect/qswift fly vps${id} ${image} delete
	/root/cpaneldirect/qswift fly vps${id} ${image}
	if [ -e /vz/private/${id}/root.hdd/root.hdd ]; then
		vzctl snapshot-umount $id --id "$UUID"
		vzctl snapshot-delete $id --id "$UUID"
		rmdir /vz/${image}
	else 
		/bin/rm -rf /vz/${image}
	fi
fi
curl --connect-timeout 60 --max-time 240 -k -d action=backup_status -d vps_id=${id} "$url" 2>/dev/null

