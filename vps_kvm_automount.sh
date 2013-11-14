#!/bin/bash
#set -x
if [ "$*" = "" ]; then
	echo "Correct Syntax:"
	echo "$0 <vzid> [mount point] [unmount]"
	exit
fi
VZID=$1
shift
UNMOUNT=0
TARGET=/mnt
while [ $# -gt 0 ]; do
	if [ "$1" == "unmount" ] || [ "$1" == "umount" ]; then
		UNMOUNT=1
		if [ -d ${TARGET}/boot ]; then
			umount ${TARGET}/boot
		fi
		umount ${TARGET}
		shift
	elif [ "$1" != "" ]; then
		TARGET=$1
		shift
	else
		echo "Unknown Argument $1"
		exit
		shift
	fi
done
if [ ! -d ${TARGET} ]; then
	echo "Target Directory ${TARGET} Does Not Exist, please create it"
	exit
fi
if [ "$(kpartx 2>&1 |grep sync)" = "" ]; then
	kpartxopts=""
else
	kpartxopts="-s"
fi
if [[ $(fdisk -v | sed s#".*fdisk (util-linux \(.*\))"#"\1"#g) > 2.17 ]]; then
	fdiskopts="-c"
else
	fdiskopts=""
fi
kpartx $kpartxopts -av /dev/vz/$VZID
sync
sleep 1s
#ls /dev/mapper |grep "${VZID}"
if [ -e /dev/mapper/vz-${VZID}p1 ]; then
	VZDEV="/dev/mapper/"
    mapprefix="vz-"
else
	VZDEV="/dev/mapper/"
    mapprefix=""
fi
OIFS="$IFS"
IFS="
"
boot_dir="";
found_boot=0;
found_root=0;
for part in $(fdisk ${fdiskopts} -u -l /dev/vz/${VZID} |grep ^/dev | sed s#"\*"#""#g | awk '{ print $1 " " $6 }' | sed s#"\/dev\/vz"#"\/dev\/mapper"#g); do
	#echo "All: $part"
	partdev="$(echo $part | awk '{ print $1 }' | sed s#"/dev/vz/"#"/dev/mapper/"#g)"
    partname="${partdev#$VZDEV}"
    partname="${partname#$mapprefix}"
    mapname="${mapprefix}${partname}"
    partdev="${VZDEV}${mapname}"
	parttype="$(echo $part | awk '{ print $2 }')"
	echo "VZID $VZID  PATH $VZDEV Prefix: $mapprefix  PartName: $partname  Combined: $mapname    Type: $parttype "
	# check if linux partition
	if [ $UNMOUNT == 1 ]; then
		umount /tmp/${mapname}
		rmdir /tmp/${mapname}
	elif [ "$(file -L -s /dev/mapper/${mapname} | grep -e ": Linux rev [0-9\.]* \(.*\) filesystem")" != "" ]; then
		mounttype="$(file -L -s /dev/mapper/${mapname} |  sed -e s#"^.*: Linux rev [0-9\.]* \(.*\) filesystem.*$"#"\1"#g)"
		if [ "$mounttype" != "" ]; then
			mkdir -p /tmp/${mapname}
			#mount -t $mounttype ${partdev} /tmp/${mapname}
			mount -o ro -t $mounttype /dev/mapper/${mapname} /tmp/${mapname}
			if [ -e /tmp/${mapname}/etc/fstab ]; then
				mount --bind /tmp/${mapname} ${TARGET}
				found_root=1
				echo "FSTAB:"
				grep -v -e "^$" -e "^#" /tmp/${mapname}/etc/fstab
			elif [ -d /tmp/${mapname}/grub ] && [ $found_boot == 0 ]; then
				#set boot_dir to mount it later instead of right away
				#mount --bind /tmp/${mapname} ${TARGET}/boot
				boot_dir=/tmp/${mapname}
				found_boot=1
			else
				echo "not sure where to mount ${mapname} yet"
			fi
		fi
	#elif [ "$(file -L -s ${partdev} |grep -e ":\(.*\)x86 boot sector, code ")" != "" ];then
	elif [ "$(file -L -s /dev/mapper/${mapname} |grep -e ":\(.*\)x86 boot sector")" != "" ];then
		mkdir -p /tmp/${mapname}
		mount -o ro ${partdev} /tmp/${mapname}
		if [ -e /tmp/${mapname}/pagefile.sys ] || [ -d /tmp/${mapname}/Windows ]; then
			mount --bind /tmp/${mapname} ${TARGET}
			found_boot=1
			found_root=1
		else
			echo "${mapname} is not part of main windows drive";
			umount /tmp/${mapname};
		fi
	else
		echo "Dont know how to handle partition ${partdev} Type $parttype - $(file -L -s ${VZDEV}${mapname} | cut -d: -f2-)"
	fi
done
IFS="$OIFS"

if [ $UNMOUNT == 1 ]; then
	umount ${TARGET}
	kpartx $kpartxopts -dv /dev/vz/$VZID
	echo "Finished Unmounting"
elif [ $found_root == 1 ] && [ $found_boot == 1 ]; then
    if [ "$boot_dir" != "" ]; then
		mount --bind ${boot_dir} ${TARGET}/boot
    fi
	echo "Mounted Successfully"
elif [ $found_root == 1 ]; then
	echo "Root but no Boot found"
elif [ $found_boot == 1 ]; then
	echo "Boot but no Root Found"
else
	echo "Cannot figure out any of the partitions"
fi

