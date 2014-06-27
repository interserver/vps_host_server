#!/bin/bash
sliceram=1024
iopslimitbase=35
iopslimitmodifier=7
mbpslimitbase=7
mbpslimitmodifier=2
cpulimitbase=35
cpulimitmodifier=4
cpuweightbase=5
cpuweightmodifier=1
onembyte=1048576
IFS="
"
# vcpu_shares 0-2621440-262144 weighted balance
# Within each given "period" (microseconds), a group is allowed to consume only up to "quota" microseconds of CPU time. 
# vcpu_period 1000-1000000 time in ms maybe 
# vcpu_quota 1000-18446744073709551  how much max time in ms of each period can you get
# If --live is specified, set scheduler information of a running guest. If --config is specified, affect the next boot of a persistent guest. If --current is specified, affect the current guest state. 
if [ -e /cgroup/blkio/libvirt/qemu ] || [ -e "$(ls /sys/fs/cgroup/blkio/machine/*.libvirt-qemu/blkio.throttle.read_iops_device 2>/dev/null | head -n 1)" ]; then
    if [ -e /cgroup/blkio/libvirt/qemu ]; then
        cgdir=/cgroup/blkio/libvirt/qemu;
        cgall=${cgdir}/*/blkio.throttle.read_iops_device;
    else
        cgdir=/sys/fs/cgroup/blkio/machine;
        cgall=/sys/fs/cgroup/blkio/machine/*.libvirt-qemu/blkio.throttle.read_bps_device
    fi
    for i in $(ls $cgall); do
        if [ -e /cgroup/blkio/libvirt/qemu ]; then
            cgid="$(echo "$i" | cut -d/ -f6)";
        else
            cgid="$(echo "$i" |  cut -d/ -f7 | sed s#"\.libvirt-qemu$"#""#g)"
        fi;
        id="${cgid}";
        mem="$(grep -i '<memory ' /etc/libvirt/qemu/${id}.xml |  tr '>' ' ' | tr '<' ' ' | tr \. ' ' | awk '{ print $3 }')";
        mem="$(echo $mem / 1000 |bc -l | cut -d\. -f1)";
        slices="$(grep "${id}" /root/cpaneldirect/vps.slicemap | sed s#"[[:alpha:]]"#""#g | grep "^${id}:" | cut -d: -f2)";
        if [ "$slices" = "" ]; then
            if [ "$mem" == "" ] || [ $mem -lt ${sliceram} ]; then
                slices=1;
            else
                slices="$(echo $mem / ${sliceram} |bc -l | cut -d\. -f1)";
            fi;
        fi;
        majorminor="$(ls -al /dev/vz/$(ls -al /dev/vz/$id | awk '{ print $11 }') | awk '{ print $5 ":" $6 }' |sed s#","#""#g)";
        iopslimit="$(echo "${iopslimitbase} + (${iopslimitmodifier} * ${slices})" |bc -l | cut -d\. -f1)";
        mbpslimit="$(echo "(${mbpslimitbase} + (${mbpslimitmodifier} * ${slices}))" |bc -l)";
        bpslimit="$(echo "${onembyte} * ${mbpslimit}" |bc -l | cut -d\. -f1)";
        echo "$majorminor $iopslimit" > $i;
        if [ -e /cgroup/blkio/libvirt/qemu ]; then
            echo "$majorminor $iopslimit" > $cgdir/$id/blkio.throttle.write_iops_device;
            echo "$majorminor $bpslimit" > $cgdir/$id/blkio.throttle.read_bps_device;
            echo "$majorminor $bpslimit" > $cgdir/$id/blkio.throttle.write_bps_device;
        else
            echo "$majorminor $iopslimit" > $cgdir/${id}.libvirt-qemu/blkio.throttle.write_iops_device;
            echo "$majorminor $bpslimit" > $cgdir/${id}.libvirt-qemu/blkio.throttle.read_bps_device;
            echo "$majorminor $bpslimit" > $cgdir/${id}.libvirt-qemu/blkio.throttle.write_bps_device;
        fi
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
        slices="$(grep "^${id}:" /root/cpaneldirect/vps.slicemap | cut -d: -f2)"
		if [ "$slices" = "" ]; then
            if [ "$mem" == "" ] || [ $mem -lt ${sliceram} ]; then
                slices=1
            else
                slices="$(echo $mem / ${sliceram} |bc -l | cut -d\. -f1)";
                if [ $slices -gt 16 ]; then
                    slices=16;
                fi
            fi
        fi
        iopslimit="$(echo "${iopslimitbase} + (${iopslimitmodifier} * ${slices})" |bc -l | cut -d\. -f1)";
        mbpslimit="$(echo "(${mbpslimitbase} + (${mbpslimitmodifier} * ${slices}))" |bc -l | cut -d\. -f1)";
        bpslimit="$(echo "${onembyte} * ${mbpslimit}" |bc -l | cut -d\. -f1)";
        cpulimit="$(echo "${cpulimitbase} + (${cpulimitmodifier} * ${slices})" |bc -l | cut -d\. -f1)";
        cpuweightpct="$(echo "(${cpuweightbase} + (${cpuweightmodifier} * ${slices}))" |bc -l)";
        cpuweightpower="$(echo "${cpuweightpct} / 100 * ${cpupower}" |bc -l | cut -d\. -f1)";
        echo "# VPS ID=$id HOST=${host} SLICES=${slices}, IO OPS=${iopslimit} MBPS=${mbpslimit}, CPU MAX USAGE=${cpulimit}% GARAUNTEED USAGE=${cpuweightpct}% (${cpuweightpower})";
        output="$(vzctl set $id --iolimit ${mbpslimit}M --iopslimit ${iopslimit} --cpuunits ${cpuweightpower} --cpulimit ${cpulimit} --save | grep -v "CT configuration saved to /etc/vz/conf" | sed ':a;N;$!ba;s/\n/  /g')";
		#echo $output;
    done
else
    echo "Dont know how to handle this type"
fi
