#!/bin/bash
if [ ! -e /cgroup/blkio/libvirt/qemu ]; then
	exit;
fi
sliceram=1024
iopslimitbase=25
iopslimitmodifier=5
bpslimitbase=5
bpslimitmodifier=1
onembyte=1048576
cgdir=/cgroup/blkio/libvirt/qemu;
for i in ${cgdir}/*/blkio.throttle.read_iops_device; do
	vps="$(echo "$i" | cut -d/ -f6)";
	mem="$(grep -i '<memory ' /etc/libvirt/qemu/${vps}.xml |  tr '>' ' ' | tr '<' ' ' | tr \. ' ' | awk '{ print $3 }')"
	mem="$(echo $mem / 1000 |bc -l | cut -d\. -f1)";
	if [ "$mem" == "" ] || [ $mem -lt ${sliceram} ]; then
		slices=1
	else
		slices="$(echo $mem / ${sliceram} |bc -l | cut -d\. -f1)";
	fi
	majorminor="$(ls -al /dev/vz/$(ls -al /dev/vz/$vps | awk '{ print $11 }') | awk '{ print $5 ":" $6 }' |sed s#","#""#g)";
	iopslimit="$(echo "${iopslimitbase} + (${iopslimitmodifier} * ${slices})" |bc -l | cut -d\. -f1)"
	bpslimit="$(echo "${onembyte} * (${bpslimitbase} + (${bpslimitmodifier} * ${slices}))" |bc -l | cut -d\. -f1)"
	echo "$majorminor $iopslimit" > $i;
	echo "$majorminor $iopslimit" > $cgdir/$vps/blkio.throttle.write_iops_device;
	echo "$majorminor $bpslimit" > $cgdir/$vps/blkio.throttle.read_bps_device;
	echo "$majorminor $bpslimit" > $cgdir/$vps/blkio.throttle.write_bps_device;
	echo "$iopslimit iops read/write limit set on $slices slice vps $vps (device $majorminor)";
	echo "$bpslimit bps read/write limit set on $slices slice vps $vps (device $majorminor)";
done
