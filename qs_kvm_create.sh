#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin";
export base="$(readlink -f "$(dirname "$0")")";
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
module="quickservers"
url="https://myquickserver2.interserver.net/qs_queue.php"
softraid=""
vcpu=2
size=102400
name=$1
ip=$2
if [ "$(echo "$ip" |grep ",")" != "" ]; then
	extraips="$(echo "$ip"|cut -d, -f2-|tr , " ")"
	ip="$(echo "$ip"|cut -d, -f1)"
else
	extraips=""
fi;
template=$3
memory=1048576
if [ "$template" = "windows3" ]; then
    size=52000
    memory=262144
    vcpu=1
fi
IFS="
"
if [ "$4" != "" ]; then
	size=$4
fi
if [ "$5" != "" ]; then
	memory=$5
	if [ "$memory" = "all" ]; then
		memory=$(echo "$(grep "^MemTotal" /proc/meminfo|awk "{ print \$2 }") / 100 * 70"|bc)
	fi
fi
if [ "$6" != "" ]; then
	vcpu=$6
	if [ "$vcpu" = "all" ]; then
		vcpu="$(lscpu |grep ^CPU\(s\) | awk ' { print $2 }')"
	fi
fi
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
if [ "$7" != "" ]; then
	password=$7
fi
if [ "$8" != "" ]; then
	clientip="$8"
else
	clientip=""
fi
error=0
adjust_partitions=1
export PREPATH="";
if [ -e /etc/redhat-release ] && [ $(cat /etc/redhat-release |sed s#"^[^0-9]* "#""#g|cut -c1) -le 6 ]; then
	if [ $(echo "$(e2fsck -V 2>&1 |head -n 1 | cut -d" " -f2 | cut -d"." -f1-2) * 100" | bc | cut -d"." -f1) -le 141 ]; then
		if [ ! -e /opt/e2fsprogs/sbin/e2fsck ]; then
			pushd $PWD;
			cd /admin/ports
			./install e2fsprogs
			popd;
		fi;
		export PREPATH="/opt/e2fsprogs/sbin:";
		export PATH="$PREPATH$PATH";
	fi;
fi;
device=/dev/vz/$name
if [ $# -lt 3 ]; then
	echo "Create a New KVM"
	echo " - Creates LVM"
	echo " - Clones Windows VPS/LVM"
	echo " - Rebuild DHCPD"
	echo " - Startup"
	echo "Syntax $0 <name> <ip> <template> [diskspace] [memory] [vcpu]"
	echo " ie $0 windows1337 1.2.3.4 windows1"
	error=$(($error + 1))
#check if vps exists
else
	export pool="$(virsh pool-dumpxml vz 2>/dev/null|grep "<pool"|sed s#"^.*type='\([^']*\)'.*$"#"\1"#g)"
	if [ "$pool" = "" ]; then
		${base}/create_libvirt_storage_pools.sh
		export pool="$(virsh pool-dumpxml vz 2>/dev/null|grep "<pool"|sed s#"^.*type='\([^']*\)'.*$"#"\1"#g)"
	fi
	#if [ "$(virsh pool-info vz 2>/dev/null)" != "" ]; then
	if [ "$pool" = "zfs" ]; then
        mkdir -p /vz/$name
        zfs create vz/$name
        device=/vz/$name/os.qcow2
        cd /vz
        sleep 5s;
		#virsh vol-create-as --pool vz --name $name/os.qcow2 --capacity "$size"M --format qcow2 --prealloc-metadata
		#sleep 5s;
		#device="$(virsh vol-list vz --details|grep " $name[/ ]"|awk '{ print $2 }')"
	else
		${base}/vps_kvm_lvmcreate.sh $name $size || exit
	fi
    touch /tmp/_securexinetd;
    echo "$pool pool device $device created"
	cd /etc/libvirt/qemu
	if /usr/bin/virsh dominfo $name >/dev/null 2>&1; then
		/usr/bin/virsh destroy $name
		cp $name.xml $name.xml.backup
		/usr/bin/virsh undefine $name
		mv -f $name.xml.backup $name.xml
	else
		echo "Generating XML Config"
		templatef="windows"
		if [ "$pool" != "zfs" ]; then
			grep -v -e uuid -e filterref -e "<parameter name='IP'" ${base}/$templatef.xml | sed s#"$templatef"#"$name"#g > $name.xml
		else
            grep -v -e uuid ${base}/$templatef.xml | sed -e s#"$templatef"#"$name"#g -e s#"/dev/vz/$name"#"$device"#g > $name.xml
		fi
		echo "Defining Config As VPS"
		if [ ! -e /usr/libexec/qemu-kvm ] && [ -e /usr/bin/kvm ]; then
		    sed s#"/usr/libexec/qemu-kvm"#"/usr/bin/kvm"#g -i $name.xml
		fi;
	fi
	repl="<parameter name='IP' value='$ip'/>";
	if [ "$extraips" != "" ]; then
		for i in $extraips; do
			repl="$repl\n        <parameter name='IP' value='$i'/>";
		done
	fi
    id=$(echo $name|sed s#"^\(qs\|windows\|linux\|vps\)\([0-9]*\)$"#"\2"#g)
    if [ "$id" != "$name" ]; then
        mac=$(${base}/convert_id_to_mac.sh $id $module)
        sed s#"<mac address='.*'"#"<mac address='$mac'"#g -i $name.xml
    else
        sed s#"^.*<mac address.*$"#""#g -i $name.xml
    fi

    sed s#"<\(vcpu.*\)>.*</vcpu>"#"<vcpu placement='static' current='$vcpu'>$max_cpu</vcpu>"#g -i $name.xml;
    sed s#"<memory.*memory>"#"<memory unit='KiB'>$memory</memory>"#g -i $name.xml;
    sed s#"<currentMemory.*currentMemory>"#"<currentMemory unit='KiB'>$memory</currentMemory>"#g -i $name.xml;
    sed s#"<parameter name='IP' value.*/>"#"$repl"#g -i $name.xml;
    if [ "$(grep -e "flags.*ept" -e "flags.*npt" /proc/cpuinfo)" != "" ]; then
        sed s#"<features>"#"<features>\n    <hap/>"#g -i $name.xml
    fi
    if [ "$(date "+%Z")" = "PDT" ]; then
        sed s#"America/New_York"#"America/Los_Angeles"#g -i $name.xml
    fi
    if [ -e /etc/lsb-release ]; then
        . /etc/lsb-release;
        if [ $(echo $DISTRIB_RELEASE|cut -d\. -f1) -ge 18 ]; then
            sed s#"\(<controller type='scsi' index='0'.*\)>"#"\1 model='virtio-scsi'>\n      <driver queues='$vcpu'/>"#g -i  $name.xml;
        fi;
    fi;
	/usr/bin/virsh define $name.xml
	if [ "$template" = "windows1" ]; then
		template=windows2
	fi
    if [ "$pool" = "zfs" ]; then
        if [ -e "/vz/templates/$template.qcow2" ]; then
            echo "Copy $template.qcow2 Image"
            if [ "$size" = "all" ]; then
                size=$(echo "$(zfs list vz -o available -H -p)  / (1024 * 1024)"|bc)
            fi
            if [ "$(echo "$template"|grep -i freebsd)" != "" ]; then
                cp -f /vz/templates/$template.qcow2 $device;
                qemu-img resize $device "$size"M;
            else
                qemu-img create -f qcow2 -o preallocation=metadata $device 25G
                qemu-img resize $device "$size"M;
                part=$(virt-list-partitions /vz/templates/$template.qcow2|tail -n 1)
                virt-resize --expand $part /vz/templates/$template.qcow2 $device;
            fi;
            adjust_partitions=0
        fi
        #if [ -e "/$template.img.gz" ]; then
        #    echo "Uncompressing $template.img.gz Image"
        #    gunzip "/$template.img.gz"
        #fi
        #if [ -e "/$template.img" ]; then
        #    echo "Uploading $template Image"
        #    virsh vol-upload $name "/$template.img" --pool vz
        #fi;
    elif [ -e "/$template.img.gz" ]; then
		echo "Copying $template Image"
		gzip -dc "/$template.img.gz"  | dd of=$device 2>&1 &
		#pid="$(pidof gzip)"
		pid=$!
		echo "Got gzip PID $pid";
		if [ "$(pidof gzip)" != "" ]; then
			pid="$(pidof gzip)"
			echo "Tried again, got gzpi PID $pid"
		fi
		if [ "$(echo "$pid" | grep " ")" != "" ]; then
			pid=$(pgrep -f 'gzip -dc')
			echo "Didn't like gzip pid (had a space?), going with gzip PID $pid"
		fi
		tsize=$(stat -L /proc/$pid/fd/3 -c "%s");
		echo "Got Total Size $tsize";
		if [ -z $tsize ]; then
		tsize=$(stat -c%s "/$template.img.gz");
		echo "Falling back to filesize check, got size $tsize";
		fi;
		while [ -d /proc/$pid ]; do
	copied=$(awk '/pos:/ { print $2 }' /proc/$pid/fdinfo/3)
	completed="$(echo "$copied/$tsize*100" |bc -l | cut -d\. -f1)"
	curl --connect-timeout 60 --max-time 600 -k -d action=install_progress -d progress=$completed -d server=$name "$url" 2>/dev/null
	if [ "$(grep -v idle /sys/block/md*/md/sync_action 2>/dev/null)" != "" ]; then
		softraid="$(grep -l -v idle /sys/block/md*/md/sync_action 2>/dev/null)"
		for softfile in $softraid; do
			echo idle > $softfile
		done
	fi
	echo "$completed%"
	sleep 10s
		done
	elif [ -e "/$template.img" ]; then
		echo "Copying $template Image"
		tsize=$(stat -c%s "/$template.img")
		dd if="/$template.img" of=$device >dd.progress 2>&1 &
		pid=$!
		while [ -d /proc/$pid ]; do
	sleep 9s
	kill -SIGUSR1 $pid;
	sleep 1s
	if [ -d /proc/$pid ]; then
	  copied=$(tail -n 1 dd.progress | cut -d" " -f1)
	  completed="$(echo "$copied/$tsize*100" |bc -l | cut -d\. -f1)"
	  curl --connect-timeout 60 --max-time 600 -k -d action=install_progress -d progress=$completed -d server=$name "$url" 2>/dev/null
		if [ "$(ls /sys/block/md*/md/sync_action 2>/dev/null)" != "" ] && [ "$(grep -v idle /sys/block/md*/md/sync_action 2>/dev/null)" != "" ]; then
			softraid="$(grep -l -v idle /sys/block/md*/md/sync_action 2>/dev/null)"
			for softfile in $softraid; do
				echo idle > $softfile
			done
		fi
	  echo "$completed%"
	fi
		done
		rm -f dd.progress
	else
		echo "Suspending $template For Copy"
		/usr/bin/virsh suspend $template
		echo "Copying Image"
		tsize=$(stat -c%s "/dev/vz/$template")
		dd if=/dev/vz/$template of=$device >dd.progress 2>&1 &
		pid=$!
		while [ -d /proc/$pid ]; do
	sleep 9s
	kill -SIGUSR1 $pid;
	sleep 1s
	if [ -d /proc/$pid ]; then
	  copied=$(tail -n 1 dd.progress | cut -d" " -f1)
	  completed="$(echo "$copied/$tsize*100" |bc -l | cut -d\. -f1)"
	  curl --connect-timeout 60 --max-time 600 -k -d action=install_progress -d progress=$completed -d server=$name "$url" 2>/dev/null
		if [ "$(grep -v idle /sys/block/md*/md/sync_action 2>/dev/null)" != "" ]; then
			softraid="$(grep -l -v idle /sys/block/md*/md/sync_action 2>/dev/null)"
			for softfile in $softraid; do
				echo idle > $softfile
			done
		fi
	  echo "$completed%"
	fi
		done
		rm -f dd.progress
	fi
	if [ "$softraid" != "" ]; then
	for softfile in $softraid; do
		echo check > $softfile
	done
	fi
	curl --connect-timeout 60 --max-time 600 -k -d action=install_progress -d progress=resizing -d server=$name "$url" 2>/dev/null
	sects="$(fdisk -l -u $device  | grep -e "total .* sectors$" | sed s#".*total \(.*\) sectors$"#"\1"#g)"
	t="$(fdisk -l -u $device | sed s#"\*"#""#g | grep "^/dev/vz" | tail -n 1)"
	p="$(echo $t | awk '{ print $1 }')"
	fs="$(echo $t | awk '{ print $5 }')"
	pn="$(echo "$p" | sed s#"/dev/vz/"$name"p"#""#g)"
	start="$(echo $t | awk '{ print $2 }')"
	if [ "$fs" = "83" ]; then
		echo "Resizing Last Partition To Use All Free Space"
		echo -e "d\n$pn\nn\n$pt\n$pn\n$start\n\n\nw\nprint\nq\n" | fdisk -u $device
		echo -e "d\n$pn\nn\np\n$pn\n$start\n\n\nw\nprint\nq\n" | fdisk -u $device
		kpartx $kpartxopts -av $device
		if [ -e "/dev/mapper/vz-"$name"p$pn" ]; then
			pname="vz-$name"
		else
			pname="$name"
		fi
		fsck -T -p -f /dev/mapper/"$pname"p$pn
		if [ -f "$(which resize4fs 2>/dev/null)" ]; then
			resizefs="resize4fs"
		else
			resizefs="resize2fs"
		fi
		$resizefs -p /dev/mapper/"$pname"p$pn
		mkdir -p /vz/mounts/"$name"p$pn
		mount /dev/mapper/"$pname"p$pn /vz/mounts/"$name"p$pn;
		PATH="$PREPATH/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin" echo "root:$password" | chroot /vz/mounts/"$name"p$pn chpasswd
		umount /dev/mapper/"$pname"p$pn
		kpartx $kpartxopts -d $device
	fi
	# /usr/bin/virsh setmaxmem $name $memory;
	# /usr/bin/virsh setmem $name $memory;
	# /usr/bin/virsh setvcpus $name $vcpu;
    if [ "$pool" = "zfs" ]; then
        virsh detach-disk $name vda --persistent;
        virsh attach-disk $name /vz/$name/os.qcow2 vda --targetbus virtio --driver qemu --subdriver qcow2 --type disk --sourcetype file --persistent;
        virt-customize -d $name --root-password "password:$password" --hostname "$name"
    fi;
	/usr/bin/virsh autostart $name
	mac="$(/usr/bin/virsh dumpxml $name |grep 'mac address' | cut -d\' -f2)"
	/bin/cp -f $DHCPVPS $DHCPVPS.backup
	grep -v -e "host $name " -e "fixed-address $ip;" $DHCPVPS.backup > $DHCPVPS
	echo "host $name { hardware ethernet $mac; fixed-address $ip;}" >> $DHCPVPS
	rm -f $DHCPVPS.backup
	if [ -e /etc/apt ]; then
		systemctl restart isc-dhcp-server 2>/dev/null || service isc-dhcp-server restart 2>/dev/null || /etc/init.d/isc-dhcp-server restart 2>/dev/null
	else
		systemctl restart dhcpd 2>/dev/null || service dhcpd restart 2>/dev/null || /etc/init.d/dhcpd restart 2>/dev/null;
	fi
	curl --connect-timeout 60 --max-time 600 -k -d action=install_progress -d progress=starting -d server=$name "$url" 2>/dev/null
	/usr/bin/virsh start $name;
	bash ${base}/run_buildebtables.sh;
	${base}/vps_refresh_vnc.sh $name
	vnc="$((5900 + $(virsh vncdisplay $name | cut -d: -f2 | head -n 1)))";
	if [ "$vnc" == "" ]; then
		sleep 2s;
		vnc="$((5900 + $(virsh vncdisplay $name | cut -d: -f2 | head -n 1)))";
		if [ "$vnc" == "" ]; then
			sleep 2s;
			vnc="$(virsh dumpxml $name |grep -i "graphics type='vnc'" | cut -d\' -f4)";
		fi;
	fi;
	if [ "$clientip" != "" ]; then
		${base}/vps_kvm_setup_vnc.sh $name "$clientip";
	fi;
	${base}/vps_kvm_screenshot.sh "$(($vnc - 5900))" "$url?action=screenshot&name=$name";
	${base}/vps_kvm_screenshot.sh "$(($vnc - 5900))" "$url?action=screenshot&name=$name";
	#vnc="$(virsh dumpxml $name |grep -i "graphics type='vnc'" | cut -d\' -f4)";
	sleep 1s;
	${base}/vps_kvm_screenshot.sh "$(($vnc - 5900))" "$url?action=screenshot&name=$name";
	sleep 2s;
	${base}/vps_kvm_screenshot.sh "$(($vnc - 5900))" "$url?action=screenshot&name=$name";
	/admin/kvmenable blocksmtp $name

fi
