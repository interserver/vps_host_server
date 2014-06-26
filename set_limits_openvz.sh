#!/bin/bash
if [ ! -e /etc/vz/vz.conf ]; then
	exit;
fi
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
cpupower=$(vzcpucheck |grep Power | awk '{ print $5 }')
memlimits=()
IFS="
"
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
	vzctl set $id --iolimit ${bpslimit} --iopslimit ${iopslimit} --cpuunits ${cpuweightpower} --cpulimit ${cpulimit} --save
done

