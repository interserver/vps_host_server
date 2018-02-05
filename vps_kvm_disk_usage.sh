#!/bin/bash
if [ "$(kpartx 2>&1 |grep sync)" = "" ]; then
	kpartxopts=""
else
	kpartxopts="-s"
fi
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
#set -x
IFS="
"
if [ $# -ne 1 ]; then
	name=""
else
	name=$1
fi
# Sample lvdisplay output
#/dev/vz/windows1:vz:3:1:-1:0:206848000:25250:-1:0:-1:253:0
for i in $(lvdisplay --all -c | sed s#" "#""#g | grep "/dev/vz/$name"); do
	vz="$(echo $i | cut -d: -f1)"
	vzname="$(echo "$vz" | sed s#"/dev/vz/"#""#g)"
	free=0
	total=0
#	echo "vz:$vz"
	for j in $(kpartx $kpartxopts -av $vz | cut -d" " -f3 | tail -n 1); do
#		echo "J:$j"
		# Sample sfdisk output
		#windows1p1 7
		type="$(sfdisk -d $vz | grep "^/dev/vz" | cut -d= -f1,4 | sed s#" : start= *"#" "#g | cut -d, -f1 |grep -v " 0" | sed s#"/dev/vz/"#""#g | grep "$(echo "$j"|sed s#"^vz-"#""#g)" | cut -d" " -f 2)"
#		echo "Type:$type"
		if [ $type = 83 ]; then
			reservedblocks="$(dumpe2fs -h -f /dev/mapper/$j 2>/dev/null | grep "^Reserved block count" | awk '{ print $4 }')"
			freeblocks="$(dumpe2fs -h -f /dev/mapper/$j 2>/dev/null | grep "^Free blocks" | awk '{ print $3 }')"
			blocksize="$(dumpe2fs -h -f /dev/mapper/$j 2>/dev/null | grep "^Block size" | awk '{ print $3 }')"
			totalblocks="$(dumpe2fs -h -f /dev/mapper/$j 2>/dev/null | grep "^Block count" | awk '{ print $3 }')"
			total="$(echo "$blocksize * $totalblocks / 1024" | bc -l | cut -d\. -f1)"
			free="$(echo "$blocksize * ($freeblocks - $reservedblocks) / 1024" | bc -l | cut -d\. -f1)"
			echo "$vzname:$total:$free"
		elif [ $type = 7 ]; then
			mkdir -p /vz/mounts/$j
			mount /dev/mapper/$j /vz/mounts/$j >/dev/null 2>&1
			# Sample df output
			#29:19
			space="$(df -P /vz/mounts/$j | grep -v ^Filesystem | awk '{ print $2 ":" $3 }' |sed s#"G"#""#g)"
#			echo "space:$space"
#			echo "$vz:$j:$type:$space"
			total=$(($total + $(echo "$space" | cut -d: -f1)))
			free=$(($free + $(echo "$space" | cut -d: -f2)))
			umount /vz/mounts/$j 2>/dev/null
			echo "$vzname:$total:$free"
		fi
	done
	kpartx $kpartxopts -d $vz
done	
