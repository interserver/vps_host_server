#!/bin/bash
limit=100;
cqdir=/cgroup/blkio/libvirt/qemu; 
for i in ${cqdir}/*/blkio.throttle.read_iops_device; do 
	vps="$(echo "$i" | cut -d/ -f6)"; 
	majorminor="$(ls -al /dev/vz/$(ls -al /dev/vz/$vps | awk '{ print $11 }') | awk '{ print $5 ":" $6 }' |sed s#","#""#g)"; 
	echo "$majorminor $limit" > $i; 
	echo "$majorminor $limit" > $cqdir/$vps/blkio.throttle.write_iops_device; 
	echo "$limit iops read/write limit set on $vps"; 
done
