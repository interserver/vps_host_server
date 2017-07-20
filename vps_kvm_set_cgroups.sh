#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
sliceram="1024"
OIFS="$IFS";
IFS="
";
if [ ! -d /cgroup/blkio/libvirt/qemu ]; then
	echo "CGroups Not Detected, Bailing"
else
	for i in $(grep -i '<memory ' /etc/libvirt/qemu/*xml | cut -d/ -f5 | tr '>' ' ' | tr '<' ' ' | tr \. ' ' | awk '{ print $1 " " $5 }'); do
		vps="$(echo "$i" | cut -d" " -f1)";
		mem="$(echo "$i" | cut -d" " -f2)";
		mem="$(echo "$mem" / "1000" |bc -l | cut -d\. -f1)";
		memtxt="${mem}Mb Ram";
		if [ "$mem" == "" ] || [ "$mem" -lt "${sliceram}" ]; then
			slices="1"
		else
			slices="$(echo "$mem" / "${sliceram}" |bc -l | cut -d\. -f1)";
		fi
		#cpushares="$(($slices * ${sliceram}))";
		#ioweight="$(echo "200 + (50 * $slices)" | bc -l | cut -d\. -f1)";
		cpushares="$(($slices * ${sliceram} + 2000))";
		ioweight="$(echo "600 + (50 * $slices)" | bc -l | cut -d\. -f1)";
		echo "$vps$(printf %$((15-${#vps}))s)${memtxt}$(printf %$((11-${#memtxt}))s) = ${slices}$(printf %$((2-${#slices}))s) Slices -----> IO: $ioweight$(printf %$((6-${#ioweight}))s)CPU: $cpushares";
		virsh schedinfo "$vps" --set cpu_shares=${cpushares} --current >/dev/null;
		virsh schedinfo "$vps" --set cpu_shares=${cpushares} --config >/dev/null;
		virsh blkiotune "$vps" --weight "$ioweight" --current >/dev/null;
		virsh blkiotune "$vps" --weight "$ioweight" --config >/dev/null;
	done;
fi
IFS="$OIFS";
