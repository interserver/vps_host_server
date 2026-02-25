#!/bin/bash
vps=vpstest
myip=70.44.33.193
root="$2"
ip=206.72.195.91
imagedir="$(readlink -f "$(dirname "$1")")"
template="$(basename "$1")"

export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/usr/sbin:/sbin:/bin:/usr/bin";
virsh destroy ${vps} 2>/dev/null;
rm -f /etc/xinetd.d/${vps};
service xinetd restart 2>/dev/null || /etc/init.d/xinetd restart 2>/dev/null;
virsh autostart --disable ${vps} 2>/dev/null;
virsh managedsave-remove ${vps} 2>/dev/null;
virsh undefine ${vps};
export pool="$(virsh pool-dumpxml vz 2>/dev/null|grep "<pool"|sed s#"^.*type='\([^']*\)'.*$"#"\1"#g)"
if [ "$pool" = "zfs" ]; then
  device="$(virsh vol-list vz --details|grep " ${vps}[/ ]"|awk '{ print $2 }')";
else
  device="/dev/vz/${vps}";
  kpartx -dv $device;
fi
if [ "$pool" = "zfs" ]; then
  virsh vol-delete --pool vz ${vps}/os.qcow2 2>/dev/null;
  virsh vol-delete --pool vz ${vps} 2>/dev/null;
  zfs list -t snapshot|grep "/${vps}@"|cut -d" " -f1|xargs -r -n 1 zfs destroy -v;
  zfs destroy vz/${vps};
  if [ -e /vz/${vps} ]; then
	rmdir /vz/${vps};
  fi;
else
  lvremove -f $device;
fi


export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin";
function install_gz_image() {
    source="$1";
    device="$2";
    echo "Copying $source Image"
    tsize=$(stat -c%s "$source")
    gzip -dc "/$source"  | dd of=$device 2>&1 &
    pid=$!
    echo "Got DD PID $pid";
    sleep 2s;
    if [ "$(pidof gzip)" != "" ]; then
        pid="$(pidof gzip)";
        echo "Tried again, got gzip PID $pid";
    fi;
    if [ "$(echo "$pid" | grep " ")" != "" ]; then
        pid=$(pgrep -f 'gzip -dc');
        echo "Didn't like gzip pid (had a space?), going with gzip PID $pid";
    fi;
    tsize="$(stat -L /proc/$pid/fd/3 -c "%s")";
    echo "Got Total Size $tsize";
    if [ -z $tsize ]; then
        tsize="$(stat -c%s "/$source")";
        echo "Falling back to filesize check, got size $tsize";
    fi;
    while [ -d /proc/$pid ]; do
        copied=$(awk '/pos:/ { print $2 }' /proc/$pid/fdinfo/3);
        completed="$(echo "$copied/$tsize*100" |bc -l | cut -d\. -f1)";
        if [ "$(ls /sys/block/md*/md/sync_action 2>/dev/null)" != "" ] && [ "$(grep -v idle /sys/block/md*/md/sync_action 2>/dev/null)" != "" ]; then
            export softraid="$(grep -l -v idle /sys/block/md*/md/sync_action 2>/dev/null)";
            for softfile in $softraid; do
                echo idle > $softfile;
            done;
        fi;
        echo "$completed%";
        sleep 10s
    done
}
function install_image() {
    source="$1";
    device="$2";
    echo "Copying Image";
    tsize=$(stat -c%s "$source");
    dd "if=$source" "of=$device" >dd.progress 2>&1 &
    pid=$!
    while [ -d /proc/$pid ]; do
        sleep 9s;
        kill -SIGUSR1 $pid;
        sleep 1s;
        if [ -d /proc/$pid ]; then
            copied=$(tail -n 1 dd.progress | cut -d" " -f1);
            completed="$(echo "$copied/$tsize*100" |bc -l | cut -d\. -f1)";
            if [ "$(ls /sys/block/md*/md/sync_action 2>/dev/null)" != "" ] && [ "$(grep -v idle /sys/block/md*/md/sync_action 2>/dev/null)" != "" ]; then
                export softraid="$(grep -l -v idle /sys/block/md*/md/sync_action 2>/dev/null)";
                for softfile in $softraid; do
                    echo idle > $softfile;
                done;
            fi;
            echo "$completed%";
        fi;
    done;
    rm -f dd.progress;
}
IFS="
"
softraid=""
error=0
adjust_partitions=1
export PREPATH="";
if [ "vps" = "quickservers" ]; then
    export url="https://myvps.interserver.net/qs_queue.php"
    export size=all
    export memory=$(echo "$(grep "^MemTotal" /proc/meminfo|awk "{ print \$2 }") / 100 * 70"|bc)
    export vcpu="$(lscpu |grep ^CPU\(s\) | awk ' { print $2 }')"
else
    export url="https://myvps.interserver.net/vps_queue.php"
    export size=30720
    export memory=2097152
    export vcpu=1
fi
if [ "$(kpartx 2>&1 |grep sync)" = "" ]; then
    kpartxopts=""
else
    kpartxopts="-s"
fi
if [ -e /etc/dhcp/dhcpd.vps ]; then
    DHCPVPS=/etc/dhcp/dhcpd.vps
else
    DHCPVPS=/etc/dhcpd.vps
fi
if [ "$(echo "$ip" |grep ",")" != "" ]; then
    extraips="$(echo "$ip"|cut -d, -f2-|tr , " ")"
    ip="$(echo "$ip"|cut -d, -f1)"
else
    extraips=""
fi;
if [ $vcpu -gt 8 ]; then
    max_cpu=$vcpu
else
    max_cpu=8
fi
if [ $memory -gt 16384000 ]; then
    max_memory=$memory
else
    max_memory=16384000;
fi
if [ -e /etc/redhat-release ] && [ $(cat /etc/redhat-release |sed s#"^[^0-9]* "#""#g|cut -c1) -le 6 ]; then
    if [ $(echo "$(e2fsck -V 2>&1 |head -n 1 | cut -d" " -f2 | cut -d"." -f1-2) * 100" | bc | cut -d"." -f1) -le 141 ]; then
        if [ ! -e /opt/e2fsprogs/sbin/e2fsck ]; then
            pushd $PWD;
            cd /admin/ports
            ./install e2fsprogs
            popd;
        fi;
        export PREPATH="/opt/e2fsprogs/sbin:$PATH";
        export PATH="$PREPATH$PATH";
    fi;
fi;
device=/dev/vz/${vps}
export pool="$(virsh pool-dumpxml vz 2>/dev/null|grep "<pool"|sed s#"^.*type='\([^']*\)'.*$"#"\1"#g)"
if [ "$pool" = "" ]; then
    /root/cpaneldirect/create_libvirt_storage_pools.sh
    export pool="$(virsh pool-dumpxml vz 2>/dev/null|grep "<pool"|sed s#"^.*type='\([^']*\)'.*$"#"\1"#g)"
fi
#if [ "$(virsh pool-info vz 2>/dev/null)" != "" ]; then
if [ "$pool" = "zfs" ]; then
    mkdir -p /vz/${vps}
    zfs create vz/${vps}
    device=/vz/${vps}/os.qcow2
    cd /vz
    while [ ! -e /vz/${vps} ]; do
        sleep 1;
    done
    #virsh vol-create-as --pool vz --name ${vps}/os.qcow2 --capacity "$size"M --format qcow2 --prealloc-metadata
    #sleep 5s;
    #device="$(virsh vol-list vz --details|grep " ${vps}[/ ]"|awk '{ print $2 }')"
else
    /root/cpaneldirect/vps_kvm_lvmcreate.sh ${vps} $size || exit
fi
touch /tmp/_securexinetd;
echo "$pool pool device $device created"
cd /etc/libvirt/qemu
if /usr/bin/virsh dominfo ${vps} >/dev/null 2>&1; then
    /usr/bin/virsh destroy ${vps}
    cp ${vps}.xml ${vps}.xml.backup
    /usr/bin/virsh undefine ${vps}
    mv -f ${vps}.xml.backup ${vps}.xml
else
    echo "Generating XML Config"
    if [ "$pool" != "zfs" ]; then
        grep -v -e uuid -e filterref -e "<parameter name='IP'" /root/cpaneldirect/windows.xml | sed s#"windows"#"${vps}"#g > ${vps}.xml
    else
        grep -v -e uuid /root/cpaneldirect/windows.xml | sed -e s#"windows"#"${vps}"#g -e s#"/dev/vz/${vps}"#"$device"#g > ${vps}.xml
    fi
    echo "Defining Config As VPS"
    if [ ! -e /usr/libexec/qemu-kvm ] && [ -e /usr/bin/kvm ]; then
      sed s#"/usr/libexec/qemu-kvm"#"/usr/bin/kvm"#g -i ${vps}.xml
    fi;
fi
if [ "vps" = "quickservers" ]; then
    sed -e s#"^.*<parameter name='IP.*$"#""#g -e  s#"^.*filterref.*$"#""#g -i ${vps}.xml
else
    repl="<parameter name='IP' value='$ip'/>";
    if [ "$extraips" != "" ]; then
        for i in $extraips; do
            repl="$repl\n        <parameter name='IP' value='$i'/>";
        done
    fi
    sed s#"<parameter name='IP' value.*/>"#"$repl"#g -i ${vps}.xml;
fi

id=$(echo ${vps}|sed s#"^\(qs\|windows\|linux\|vps\)\([0-9]*\)$"#"\2"#g)
if [ "$id" != "${vps}" ]; then
    mac=$(/root/cpaneldirect/convert_id_to_mac.sh $id vps)
    sed s#"<mac address='.*'"#"<mac address='$mac'"#g -i ${vps}.xml
else
    sed s#"^.*<mac address.*$"#""#g -i ${vps}.xml
fi
sed s#"<\(vcpu.*\)>.*</vcpu>"#"<vcpu placement='static' current='$vcpu'>$max_cpu</vcpu>"#g -i ${vps}.xml;
sed s#"<memory.*memory>"#"<memory unit='KiB'>$memory</memory>"#g -i ${vps}.xml;
sed s#"<currentMemory.*currentMemory>"#"<currentMemory unit='KiB'>$memory</currentMemory>"#g -i ${vps}.xml;
sed s#"<parameter name='IP' value.*/>"#"$repl"#g -i ${vps}.xml;
if [ "$(grep -e "flags.*ept" -e "flags.*npt" /proc/cpuinfo)" != "" ]; then
    sed s#"<features>"#"<features>\n    <hap/>"#g -i ${vps}.xml
fi
if [ "$(date "+%Z")" = "PDT" ]; then
    sed s#"America/New_York"#"America/Los_Angeles"#g -i ${vps}.xml
fi
if [ -e /etc/lsb-release ]; then
    . /etc/lsb-release;
    if [ $(echo $DISTRIB_RELEASE|cut -d\. -f1) -ge 18 ]; then
        sed s#"\(<controller type='scsi' index='0'.*\)>"#"\1 model='virtio-scsi'>\n      <driver queues='$vcpu'/>"#g -i  ${vps}.xml;
    fi;
fi;
/usr/bin/virsh define ${vps}.xml
# /usr/bin/virsh setmaxmem ${vps} $memory;
# /usr/bin/virsh setmem ${vps} $memory;
# /usr/bin/virsh setvcpus ${vps} $vcpu;
mac="$(/usr/bin/virsh dumpxml ${vps} |grep 'mac address' | cut -d\' -f2)";
/bin/cp -f $DHCPVPS $DHCPVPS.backup;
grep -v -e "host ${vps} " -e "fixed-address $ip;" $DHCPVPS.backup > $DHCPVPS
echo "host ${vps} { hardware ethernet $mac; fixed-address $ip; }" >> $DHCPVPS
rm -f $DHCPVPS.backup;
if [ -e /etc/apt ]; then
    systemctl restart isc-dhcp-server 2>/dev/null || service isc-dhcp-server restart 2>/dev/null || /etc/init.d/isc-dhcp-server restart 2>/dev/null
else
    systemctl restart dhcpd 2>/dev/null || service dhcpd restart 2>/dev/null || /etc/init.d/dhcpd restart 2>/dev/null;
fi
if [ "$pool" = "zfs" ]; then
    if [ -e "${imagedir}/${template}.qcow2" ]; then
        echo "Copy ${template}.qcow2 Image"
        if [ "$size" = "all" ]; then
            size=$(echo "$(zfs list vz -o available -H -p)  / (1024 * 1024)"|bc)
        fi
        if [ "$(echo "${template}"|grep -i freebsd)" != "" ]; then
            cp -f ${imagedir}/${template}.qcow2 $device;
            qemu-img resize $device "$size"M;
        else
            qemu-img create -f qcow2 -o preallocation=metadata $device 25G
            qemu-img resize $device "$size"M;
            part=$(virt-list-partitions ${imagedir}/${template}.qcow2|tail -n 1)
            backuppart=$(virt-list-partitions ${imagedir}/${template}.qcow2|head -n 1)
            virt-resize --expand $part ${imagedir}/${template}.qcow2 $device || virt-resize --expand $backuppart ${imagedir}/${template}.qcow2 $device ;
        fi;
        virsh detach-disk ${vps} vda --persistent;
        virsh attach-disk ${vps} /vz/${vps}/os.qcow2 vda --targetbus virtio --driver qemu --subdriver qcow2 --type disk --sourcetype file --persistent;
        virsh dumpxml ${vps} > vps.xml
        sed s#"type='qcow2'/"#"type='qcow2' cache='writeback' discard='unmap'/"#g -i vps.xml
        virsh define vps.xml
        rm -f vps.xml
        virt-customize -d ${vps} --root-password password:"${root}" --hostname "${vps}"
        adjust_partitions=0
    fi
elif [ "$(echo ${template} | cut -c1-7)" = "http://" ] || [ "$(echo ${template} | cut -c1-8)" = "https://" ] || [ "$(echo ${template} | cut -c1-6)" = "ftp://" ]; then
    adjust_partitions=0
    echo "Downloading ${template} Image"
    /root/cpaneldirect/vps_get_image.sh "${template}"
    if [ ! -e "/image_storage/image.raw.img" ]; then
        echo "There must have been a problem, the image does not exist"
        error=$(($error + 1))
    else
        install_image "/image_storage/image.raw.img" "$device"
        echo "Removing Downloaded Image"
        umount /image_storage
        virsh vol-delete --pool vz image_storage
        rmdir /image_storage
    fi
else
    found=0;
    for source in "${imagedir}/${template}.img.gz" "/templates/${template}.img.gz" "/${template}.img.gz"; do
        if [ $found -eq 0 ] && [ -e "$source" ]; then
            found=1;
            install_gz_image "$source" "$device"
        fi;
    done;
    for source in "${imagedir}/${template}" "${imagedir}/${template}.img" "/templates/${template}.img" "/${template}.img" "/dev/vz/${template}"; do
        if [ $found -eq 0 ] && [ -e "$source" ]; then
            found=1;
            install_image "$source" "$device"
        fi;
    done;
    if [ $found -eq 0 ]; then
        echo "Template Does Not Exist"
        error=$(($error + 1))
    fi;
fi
touch /tmp/_securexinetd;
if [ "$softraid" != "" ]; then
    for softfile in $softraid; do
        echo check > $softfile
    done
fi
echo "Errors: $error  Adjust Partitions: $adjust_partitions";
if [ $error -eq 0 ]; then
    if [ "$adjust_partitions" = "1" ]; then
        sects="$(fdisk -l -u $device  | grep sectors$ | sed s#"^.* \([0-9]*\) sectors$"#"\1"#g)"
        t="$(fdisk -l -u $device | sed s#"\*"#""#g | grep "^$device" | tail -n 1)"
        p="$(echo $t | awk '{ print $1 }')"
        fs="$(echo $t | awk '{ print $5 }')"
        if [ "$(echo "$fs" | grep "[A-Z]")" != "" ]; then
            fs="$(echo $t | awk '{ print $6 }')"
        fi;
        pn="$(echo "$p" | sed s#"$device[p]*"#""#g)"
        if [ $pn -gt 4 ]; then
            pt=l
        else
            pt=p
        fi
        start="$(echo $t | awk '{ print $2 }')"
        if [ "$fs" = "83" ]; then
            echo "Resizing Last Partition To Use All Free Space (Sect $sects P $p FS $fs PN $pn PT $pt Start $start"
            echo -e "d\n$pn\nn\n$pt\n$pn\n$start\n\n\nw\nprint\nq\n" | fdisk -u $device
            kpartx $kpartxopts -av $device
            pname="$(ls /dev/mapper/vz-"${vps}"p$pn /dev/mapper/vz-${vps}$pn /dev/mapper/"${vps}"p$pn /dev/mapper/${vps}$pn 2>/dev/null | cut -d/ -f4 | sed s#"$pn$"#""#g)"
            e2fsck -p -f /dev/mapper/$pname$pn
            if [ -f "$(which resize4fs 2>/dev/null)" ]; then
                resizefs="resize4fs"
            else
                resizefs="resize2fs"
            fi
            $resizefs -p /dev/mapper/$pname$pn
            mkdir -p /vz/mounts/${vps}$pn
            mount /dev/mapper/$pname$pn /vz/mounts/${vps}$pn;
            PATH="$PREPATH/usr/local/sbin:/usr/local/bin:/root/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/X11R6/bin" \
            echo root:"${root}" | chroot /vz/mounts/${vps}$pn chpasswd || \
            php /root/cpaneldirect/vps_kvm_password_manual.php "${root}" "/vz/mounts/${vps}$pn"
            if [ -e /vz/mounts/${vps}$pn/home/kvm ]; then
                echo kvm:"${root}" | chroot /vz/mounts/${vps}$pn chpasswd
            fi;
            umount /dev/mapper/$pname$pn
            kpartx $kpartxopts -d $device
        else
            echo "Skipping Resizing Last Partition FS is not 83. Space (Sect $sects P $p FS $fs PN $pn PT $pt Start $start"
        fi
    fi
    touch /tmp/_securexinetd;
    /usr/bin/virsh autostart ${vps};
    /usr/bin/virsh start ${vps};
    if [ "$pool" != "zfs" ]; then
        bash /root/cpaneldirect/run_buildebtables.sh;
    fi;
    if [ "vps" = "vps" ]; then
        if [ ! -d /cgroup/blkio/libvirt/qemu ]; then
            echo "CGroups Not Detected, Bailing";
        else
            slices="$(echo $memory / 1000 / 512 |bc -l | cut -d\. -f1)";
            cpushares="$(($slices * 512))";
            ioweight="$(echo "400 + (37 * $slices)" | bc -l | cut -d\. -f1)";
            virsh schedinfo ${vps} --set cpu_shares=$cpushares --current;
            virsh schedinfo ${vps} --set cpu_shares=$cpushares --config;
            virsh blkiotune ${vps} --weight $ioweight --current;
            virsh blkiotune ${vps} --weight $ioweight --config;
        fi;
    fi;
    /root/cpaneldirect/tclimit $ip;
    if [ "${myip}" != "" ]; then
        /root/cpaneldirect/provirted.phar vnc setup ${vps} ${myip};
    fi;
    /root/cpaneldirect/vps_refresh_vnc.sh ${vps}
    vnc="$((5900 + $(virsh vncdisplay ${vps} | cut -d: -f2 | head -n 1)))";
    if [ "$vnc" == "" ]; then
        sleep 2s;
        vnc="$((5900 + $(virsh vncdisplay ${vps} | cut -d: -f2 | head -n 1)))";
        if [ "$vnc" == "" ]; then
            sleep 2s;
            vnc="$(virsh dumpxml ${vps} |grep -i "graphics type='vnc'" | cut -d\' -f4)";
        fi;
    fi;
    /admin/kvmenable blocksmtp ${vps}
    if [ "vps" = "vps" ]; then
        /admin/kvmenable ebflush;
        /root/cpaneldirect/buildebtablesrules | sh
    fi
    service xinetd restart
fi;
rm -f /tmp/_securexinetd;
/root/cpaneldirect/provirted.phar vnc setup ${vps} ${myip}
c=0
found=0
while [ $found -eq 0 ] && [ $c -le 100 ]; do
	ping ${ip} -c 1 && found=1
	c=$(($c + 1))
done
echo "$template Found $found after $c" | tee -a test.log
if [ $found -eq 1 ]; then
                ssh-keygen -f ~/.ssh/known_hosts -R ${ip}
	sleep 10s
                if /root/cpaneldirect/templates/test_ssh.expect ${ip} root "${root}"; then
                        echo "$template Good Login" | tee -a test.log
                else
                        echo "$template Failed Login" | tee -a test.log
                fi
fi
