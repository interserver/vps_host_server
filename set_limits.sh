#!/bin/bash
sliceram=1024
iopslimitbase=25
iopslimitmodifier=5
bpslimitbase=5
bpslimitmodifier=1
cpulimitbase=20
cpulimitmodifier=5
cpuweightbase=2
cpuweightmodifier=1
onembyte=1048576
IFS="
"
if [ -e /cgroup/blkio/libvirt/qemu ]; then
	cgdir=/cgroup/blkio/libvirt/qemu;
	for i in ${cgdir}/*/blkio.throttle.read_iops_device; do
		id="$(echo "$i" | cut -d/ -f6)";
		mem="$(grep -i '<memory ' /etc/libvirt/qemu/${id}.xml |  tr '>' ' ' | tr '<' ' ' | tr \. ' ' | awk '{ print $3 }')"
		mem="$(echo $mem / 1000 |bc -l | cut -d\. -f1)";
		if [ "$mem" == "" ] || [ $mem -lt ${sliceram} ]; then
			slices=1
		else
			slices="$(echo $mem / ${sliceram} |bc -l | cut -d\. -f1)";
		fi
		majorminor="$(ls -al /dev/vz/$(ls -al /dev/vz/$id | awk '{ print $11 }') | awk '{ print $5 ":" $6 }' |sed s#","#""#g)";
		iopslimit="$(echo "${iopslimitbase} + (${iopslimitmodifier} * ${slices})" |bc -l | cut -d\. -f1)"
		mbpslimit="$(echo "(${bpslimitbase} + (${bpslimitmodifier} * ${slices}))" |bc -l)"
		bpslimit="$(echo "${onembyte} * ${mbpslimit}" |bc -l | cut -d\. -f1)"
		echo "$majorminor $iopslimit" > $i;
		echo "$majorminor $iopslimit" > $cgdir/$id/blkio.throttle.write_iops_device;
		echo "$majorminor $bpslimit" > $cgdir/$id/blkio.throttle.read_bps_device;
		echo "$majorminor $bpslimit" > $cgdir/$id/blkio.throttle.write_bps_device;
		echo "# VPS ID=$id SLICES=${slices}, IO OPS=${iopslimit} MBPS=${mbpslimit}"
		#, CPU MAX USAGE=${cpulimit}% GARAUNTEED USAGE=${cpuweightpct}% (${cpuweightpower})"
		#echo "$iopslimit iops read/write limit set on $slices slice vps $id (device $majorminor)";
		#echo "$bpslimit bps read/write limit set on $slices slice vps $id (device $majorminor)";
	done
elif [ -e /etc/vz/vz.conf ]; then
	cpupower=$(vzcpucheck |grep Power | awk '{ print $5 }')
	memlimits=()
	for line in $(vzmemcheck -vA | awk '{ print $1 " " $9 }' |grep -E "^[[:digit:]]+ [[:digit:]]" | cut -d\. -f1); do
		id="$(echo "$line" | awk '{ print $1 }')"
		mem="$(echo "$line" | awk '{ print $2 }')"
		memlimits[$id]="$mem"
	done
	for line in $(vzlist -Hto ctid,status,hostname); do
		id="$(echo "$line" | awk '{ print $1 }')"
		status="$(echo "$line" | awk '{ print $2 }')"
		host="$(echo "$line" | awk '{ print $3 }')"
		mem="${memlimits[$id]}"
		if [ "$mem" == "" ] || [ $mem -lt ${sliceram} ]; then
			slices=1
		else
			slices="$(echo $mem / ${sliceram} |bc -l | cut -d\. -f1)";
			if [ $slices -gt 16 ]; then
				slices=16;
			fi
		fi
		iopslimit="$(echo "${iopslimitbase} + (${iopslimitmodifier} * ${slices})" |bc -l | cut -d\. -f1)"
		mbpslimit="$(echo "(${bpslimitbase} + (${bpslimitmodifier} * ${slices}))" |bc -l | cut -d\. -f1)"
		bpslimit="$(echo "${onembyte} * ${mbpslimit}" |bc -l | cut -d\. -f1)"
		cpulimit="$(echo "${cpulimitbase} + (${cpulimitmodifier} * ${slices})" |bc -l | cut -d\. -f1)"
		cpuweightpct="$(echo "(${cpuweightbase} + (${cpuweightmodifier} * ${slices}))" |bc -l)"
		cpuweightpower="$(echo "${cpuweightpct} / 100 * ${cpupower}" |bc -l | cut -d\. -f1)"
		echo "# VPS ID=$id HOST=${host} SLICES=${slices}, IO OPS=${iopslimit} MBPS=${mbpslimit}, CPU MAX USAGE=${cpulimit}% GARAUNTEED USAGE=${cpuweightpct}% (${cpuweightpower})"
		vzctl set $id --iolimit ${mbpslimit}M --iopslimit ${iopslimit} --cpuunits ${cpuweightpower} --cpulimit ${cpulimit} --save
	done
else
	echo "Dont know how to handle this type"
fi
