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
cd "$(readlink -f "$(dirname "$0")")"
INSTDIR="$(pwd -L)"
if which virsh >/dev/null 2>&1; then
	if ! virsh dominfo $vzid >/dev/null 2>&1; then
		echo "Invalid VPS $vzid"
		curl --connect-timeout 60 --max-time 600 -k -d action=backup_status -d vps_id=${id} "$url" 2>/dev/null
		exit;
	fi
	if [ -e /${image} ]; then
		echo "Invalid Image name - directory exists";
		curl --connect-timeout 60 --max-time 600 -k -d action=backup_status -d vps_id=${id} "$url" 2>/dev/null
		exit;
	fi
	/admin/swift/c mkdir_p vps${id} --force
	lvcreate --size 10000m --snapshot --name snap$id /dev/vz/$vzid
	sync
	mkdir -p /${image}
	if which guestmount >/dev/null 2>/dev/null; then 
		guestmount -a /dev/vz/snap$id -i --ro /${image} || $INSTDIR/vps_kvm_automount.sh snap${id} /${image} readonly
	else
		$INSTDIR/vps_kvm_automount.sh snap${id} /${image} readonly
	fi
	/admin/swift/c fly vps${id} /${image} delete
	/admin/swift/c fly vps${id} /${image}
	if which guestunmount >/dev/null 2>/dev/null; then 
		guestunmount /${image} || fusermount -u /${image}
	elif which guestmount >/dev/null 2>/dev/null; then 
		fusermount -u /${image}
	else
		$INSTDIR/vps_kvm_automount.sh snap${id} /${image} unmount
	fi
	$INSTDIR/vps_kvm_automount.sh snap${id} /${image} unmount
	rmdir /${image}
	echo y | lvremove /dev/vz/snap${id}
else
	VZPARTITION=`vzlist -H -o private $vzid | cut -d/ -f2`;
	if [ "${image}" = "" ]; then
		echo "Error: image variable is blank";
		exit;
	fi
	if [ "$VZPARTITION" = "" ]; then
		echo "Got a blank VZPARTITION, vps may not exist";
		exit;
	elif [ "$VZPARTITION" = "/" ]; then
		echo "Error - returned / for $VZPARTITION";
		exit;
	fi
	if [ ! -d /${VZPARTITION} ]; then
		echo "ERROR: /${VZPARTITION} is not a directory";
		exit;
	fi
	echo "Returned partition /${VZPARTITION}";
	if ! vzlist $vzid >/dev/null 2>&1; then
		echo "Invalid VPS $vzid"
		curl --connect-timeout 60 --max-time 600 -k -d action=backup_status -d vps_id=${id} "$url" 2>/dev/null
		exit;
	fi
	if [ -e /${VZPARTITION}/${image} ]; then
		echo "ERROR: Invalid Image name - directory exists";
		curl --connect-timeout 60 --max-time 600 -k -d action=backup_status -d vps_id=${id} "$url" 2>/dev/null
		exit;
	fi
	/admin/swift/c mkdir_p vps${id} --force
	mkdir -p /${VZPARTITION}/${image}
	if [ -e /${VZPARTITION}/private/${id}/root.hdd/root.hdd ]; then 
		UUID="$(uuidgen)"
		vzctl snapshot $id --id "$UUID" --skip-suspend --skip-config
		vzctl snapshot-mount $id --id "$UUID" --target /${VZPARTITION}/${image}
	else
		rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids --exclude=/home/virtfs /${VZPARTITION}/root/${vzid}/ /${VZPARTITION}/${image}
		vzctl suspend $vzid
		rsync  -aH --delete -x --no-whole-file --inplace --numeric-ids --exclude=/home/virtfs /${VZPARTITION}/private/${vzid}/ /${VZPARTITION}/${image}
		vzctl resume $vzid
	fi
	cd /${VZPARTITION}
	/admin/swift/c fly vps${id} ${image} delete
	/admin/swift/c fly vps${id} ${image}
	if [ -e /${VZPARTITION}/private/${id}/root.hdd/root.hdd ]; then
		vzctl snapshot-umount $id --id "$UUID"
		vzctl snapshot-delete $id --id "$UUID"
		rmdir /${VZPARTITION}/${image}
	else 
		cd /${VZPARTITION}
		if [ -d ${image} ]; then
			rm -rf ${image}
		fi
	fi
fi
curl --connect-timeout 60 --max-time 600 -k -d action=backup_status -d vps_id=${id} "$url" 2>/dev/null

