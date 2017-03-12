#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
#set -x
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
url="https://myvps2.interserver.net/vps_queue.php"
softraid=""
vcpu=2
size=101000
name=$1
ip=$2
template=$3
memory=1024000
if [ "$template" = "windows3" ]; then
	size=50500
	memory=256000
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
		memory="$(echo `cat /proc/meminfo  | grep ^MemTotal | awk '{print $2}'` - 102400 | bc -l)"
	fi
fi
if [ "$6" != "" ]; then
	vcpu=$6
	if [ "$vcpu" = "all" ]; then
		vcpu="$(lscpu |grep ^CPU\(s\) | awk ' { print $2 }')"
	fi
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
if [ -e /etc/redhat-release ] && [ $(cat /etc/redhat-release| cut -d" " -f3 | cut -d"." -f1) -le 6 ]; then
	if [ $(echo "$(e2fsck -V 2>&1 |head -n 1 | cut -d" " -f2 | cut -d"." -f1-2) * 100" | bc | cut -d"." -f1) -le 141 ]; then
		if [ ! -e /opt/e2fsprogs/sbin/e2fsck ]; then
			pushd $PWD;
			cd /admin/ports
			./install e2fsprogs
			popd;
		fi;
		export PREPATH="/opt/e2fsprogs/sbin:";
		export PATH="${PREPATH}${PATH}";
	fi;
fi;
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
	/root/cpaneldirect/vps_kvm_lvmcreate.sh ${name} ${size}
	cd /etc/libvirt/qemu
	if /usr/bin/virsh dominfo ${name} >/dev/null 2>&1; then
		/usr/bin/virsh destroy ${name}
		cp ${name}.xml ${name}.xml.backup
		/usr/bin/virsh undefine ${name}
		mv -f ${name}.xml.backup ${name}.xml
	else
		echo "Generating XML Config"
		if [ "${template:0:7}" = "windows" ]; then
			templatef="windows"
		else
			templatef="linux"
		fi
		grep -v -e uuid -e "mac address" /root/cpaneldirect/${templatef}.xml | sed s#"${templatef}"#"${name}"#g > ${name}.xml
		echo "Defining Config As VPS"
		if [ ! -e /usr/libexec/qemu-kvm ] && [ -e /usr/bin/kvm ]; then
#   sed s#"rhel5.4.0"#"pc-1.0"#g -i ${name}.xml
   sed s#"/usr/libexec/qemu-kvm"#"/usr/bin/kvm"#g -i ${name}.xml
#  elif [ "$template" = "windows2012" ]; then
#   sed s#"rhel5.4.0"#"pc"#g -i ${name}.xml
#  elif [ "$(/usr/libexec/qemu-kvm -machine ? |grep rhel6.6.0)" != "" ]; then
#   sed s#"rhel5.4.0"#"rhel6.6.0"#g -i ${name}.xml
#  elif [ "$(/usr/libexec/qemu-kvm -machine ? |grep rhel6.0.0)" != "" ]; then
#   sed s#"rhel5.4.0"#"rhel6.0.0"#g -i ${name}.xml
#  elif [ "$(/usr/libexec/qemu-kvm -machine ? |grep rhel5.4.0)" = "" ]; then
#   sed s#"rhel5.4.0"#"pc"#g -i ${name}.xml
#  else
#   sed s#"rhel5.4.0"#"pc"#g -i ${name}.xml
		fi;
		sed s#"<target dev='hda' bus='ide'/>"#"<target dev='vda' bus='virtio'/>"#g -i ${name}.xml
		mv -f ${name}.xml ${name}.xml.backup
		grep -v "address type='drive'" ${name}.xml.backup > ${name}.xml
		rm -f ${name}.xml.backup
	fi
	mv -f ${name}.xml ${name}.xml.backup
	cat ${name}.xml.backup | sed s#"<\(vcpu.*\)>.*</vcpu>"#"<\1>${vcpu}</vcpu>"#g | sed s#"<memory.*memory>"#"<memory>${memory}</memory>"#g | sed s#"<currentMemory.*currentMemory>"#"<currentMemory>${memory}</currentMemory>"#g > ${name}.xml
	if [ "$(grep -e "flags.*ept" -e "flags.*npt" /proc/cpuinfo | head -n 1)" != "" ]; then
		sed s#"<features>"#"<features>\n    <hap/>"#g -i ${name}.xml
	fi
	rm -f ${name}.xml.backup
	if [ ! -e /etc/apt ] || [ "$template" != "windows2" ]; then
		sed s#"<source bridge='br0'\/>"#"<model type='virtio'/>\n          <source bridge='br0'/>"#g -i ${name}.xml
	fi;
	/usr/bin/virsh define ${name}.xml
	if [ "$template" = "windows1" ]; then
		template=windows2
	fi
	if [ "${template:0:7}" = "http://" ] || [ "${template:0:8}" = "https://" ] || [ "${template:0:6}" = "ftp://" ]; then
		adjust_partitions=0
		echo "Downloading $template Image"
		/root/cpaneldirect/vps_get_image.sh "$template"
		if [ ! -e "/image_storage/image.raw.img" ]; then
			echo "There must have been a problem, the image does not exist"
			error=$(($error + 1))
		else
			echo "Copying $template Image"
			dd if=/image_storage/image.raw.img of=/dev/vz/${name} >dd.progress 2>&1 &
			pid=$!
			tsize=$(stat -c%s "/image_storage/image.raw.img")
			while [ -d /proc/$pid ]; do
			sleep 9s
			kill -SIGUSR1 $pid;
			sleep 1s
			if [ -d /proc/$pid ]; then
				copied=$(tail -n 1 dd.progress | cut -d" " -f1)
				completed="$(echo "$copied/$tsize*100" |bc -l | cut -d\. -f1)"
				curl --connect-timeout 60 --max-time 600 -k -d action=install_progress -d progress=${completed} -d server=${name} "$url" 2>/dev/null
				if [ "$(grep -v idle /sys/block/md*/md/sync_action 2>/dev/null)" != "" ]; then
					softraid="$(grep -l -v idle /sys/block/md*/md/sync_action 2>/dev/null)"
					for softfile in $softraid; do
						echo idle > $softfile
					done
				fi
			fi
			echo "$completed%"
			sleep 10s
			done
			echo "Removing Downloaded Image"
			umount /image_storage
			echo y | lvremove /dev/vz/image_storage
			rmdir /image_storage
		fi
	elif [ -e "/${template}.img.gz" ]; then
		echo "Copying $template Image"
		gzip -dc "/${template}.img.gz"  | dd of=/dev/vz/${name} 2>&1 &
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
		tsize=$(stat -L /proc/$pid/fd/3 -c "%s");
		echo "Got Total Size $tsize";
		if [ -z $tsize ]; then
			tsize=$(stat -c%s "/${template}.img.gz");
			echo "Falling back to filesize check, got size $tsize";
		fi;
		while [ -d /proc/$pid ]; do
			copied=$(awk '/pos:/ { print $2 }' /proc/$pid/fdinfo/3);
			completed="$(echo "$copied/$tsize*100" |bc -l | cut -d\. -f1)";
			curl --connect-timeout 60 --max-time 600 -k -d action=install_progress -d progress=${completed} -d server=${name} "$url" 2>/dev/null;
			if [ "$(grep -v idle /sys/block/md*/md/sync_action 2>/dev/null)" != "" ]; then
				softraid="$(grep -l -v idle /sys/block/md*/md/sync_action 2>/dev/null)";
				for softfile in $softraid; do
					echo idle > $softfile;
				done;
			fi;
			echo "$completed%";
			sleep 10s
		done
	elif [ -e "/${template}.img" ]; then
		echo "Copying $template Image"
		tsize=$(stat -c%s "/$template.img")
		dd if="/${template}.img" of=/dev/vz/${name} >dd.progress 2>&1 &
		pid=$!
		while [ -d /proc/$pid ]; do
			sleep 9s
			kill -SIGUSR1 $pid;
			sleep 1s
			if [ -d /proc/$pid ]; then
				copied=$(tail -n 1 dd.progress | cut -d" " -f1)
				completed="$(echo "$copied/$tsize*100" |bc -l | cut -d\. -f1)"
				curl --connect-timeout 60 --max-time 600 -k -d action=install_progress -d progress=${completed} -d server=${name} "$url" 2>/dev/null
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
	elif [ -e "/dev/vz/${template}" ]; then
		echo "Suspending ${template} For Copy"
		/usr/bin/virsh suspend ${template}
		echo "Copying Image"
		tsize=$(stat -c%s "/dev/vz/$template")
		dd if=/dev/vz/${template} of=/dev/vz/${name} >dd.progress 2>&1 &
		pid=$!
		while [ -d /proc/$pid ]; do
			sleep 9s
			kill -SIGUSR1 $pid;
			sleep 1s
			if [ -d /proc/$pid ]; then
			  copied=$(tail -n 1 dd.progress | cut -d" " -f1)
			  completed="$(echo "$copied/$tsize*100" |bc -l | cut -d\. -f1)"
			  curl --connect-timeout 60 --max-time 600 -k -d action=install_progress -d progress=${completed} -d server=${name} "$url" 2>/dev/null
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
	else
		echo "Template Does Not Exist"
		error=$(($error + 1))
	fi
	if [ "$softraid" != "" ]; then
		for softfile in $softraid; do
			echo check > $softfile
		done
	fi
	echo "Errors: ${error}  Adjust Partitions: ${adjust_partitions}";
	if [ $error -eq 0 ]; then
		if [ "$adjust_partitions" = "1" ]; then
			curl --connect-timeout 60 --max-time 600 -k -d action=install_progress -d progress=resizing -d server=${name} "$url" 2>/dev/null
			sects="$(fdisk -l -u /dev/vz/${name}  | grep sectors$ | sed s#"^.* \([0-9]*\) sectors$"#"\1"#g)"
			t="$(fdisk -l -u /dev/vz/${name} | sed s#"\*"#""#g | grep "^/dev/vz" | tail -n 1)"
			p="$(echo $t | awk '{ print $1 }')"
			fs="$(echo $t | awk '{ print $5 }')"
			if [ "$(echo "$fs" | grep "[A-Z]")" != "" ]; then
				fs="$(echo $t | awk '{ print $6 }')"
			fi;
			pn="$(echo "$p" | sed s#"/dev/vz/${name}[p]*"#""#g)"
			if [ $pn -gt 4 ]; then
				pt=l
			else
				pt=p
			fi
			start="$(echo $t | awk '{ print $2 }')"
			if [ "$fs" = "83" ]; then
				echo "Resizing Last Partition To Use All Free Space (Sect ${sects} P ${p} FS ${fs} PN ${pn} PT ${pt} Start ${start}"
				echo -e "d
$pn
n
$pt
$pn
$start


w
print
q
" | fdisk -u /dev/vz/${name}
				kpartx $kpartxopts -av /dev/vz/${name}
				pname="$(ls /dev/mapper/{vz-,}${name}{p,}${pn} 2>/dev/null | cut -d/ -f4 | sed s#"${pn}$"#""#g)"
				e2fsck -p -f /dev/mapper/${pname}${pn}
				if [ -f "$(which resize4fs 2>/dev/null)" ]; then
					resizefs="resize4fs"
				else
					resizefs="resize2fs"
				fi
				$resizefs -p /dev/mapper/${pname}${pn}
				mkdir -p /vz/mounts/${name}${pn}
				mount /dev/mapper/${pname}${pn} /vz/mounts/${name}${pn};
				PATH="${PREPATH}/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin" \
				echo "root:${password}" | chroot /vz/mounts/${name}${pn} chpasswd || \
				php /root/cpaneldirect/vps_kvm_password_manual.php "${password}" "/vz/mounts/${name}${pn}"
				if [ -e /vz/mounts/${name}${pn}/home/kvm ]; then
					echo "kvm:${password}" | chroot /vz/mounts/${name}${pn} chpasswd
				fi;
				umount /dev/mapper/${pname}${pn}
				kpartx $kpartxopts -d /dev/vz/${name}
			else
				echo "Skipping Resizing Last Partition FS is not 83. Space (Sect ${sects} P ${p} FS ${fs} PN ${pn} PT ${pt} Start ${start}"
			fi

			# echo "Coyping MBR"
			# dd if=/dev/vz/${template} of=/dev/vz/${name} bs=512 count=1 >/dev/null 2>&1
			# echo "Copying Partition Table"
			# dd if=/dev/vz/${template} of=/dev/vz/${name} bs=1 count=64 skip=446 seek=446 >/dev/null 2>&1
			# echo "Creating Partition Table Links"
			# /sbin/kpartx $kpartxopts -a /dev/vz/${template}
			# /sbin/kpartx $kpartxopts -a /dev/vz/${name}
			# for i in $(sfdisk -d /dev/vz/${name} | grep -v "#" | grep "/dev/vz" | cut -d= -f1,3,4 | sed s#" : start="#" "#g | sed s#", Id="#" "#g | sed s#","#""#g | sed s#",bootable"#""#g | awk '{ print $1 " " $3 " " $2 }' | grep -v " 0$" | sed s#"/dev/vz/"#""#g ); do
			#  pname="$(echo "$i" | cut -d" " -f1)"
			#  tpname="$(echo "$pname" | sed s#"${name}"#"${template}"#g)"
			#  ptype="$(echo "$i" | cut -d" " -f2)"
			#  psize="$(echo "$i" | cut -d" " -f3)"
			#  if [ $psize -gt 205000 ] && [ "$ptype" = 7 ]; then
			#   mkdir -p /vz/mounts/${tpname}
			#   mkdir -p /vz/mounts/${pname}
			#   mount /dev/mapper/${tpname} /vz/mounts/${tpname}
			#   mount /dev/mapper/${pname} /vz/mounts/${pname} >/dev/null 2>&1
			#   if [ "$(mount | grep /vz/mounts/${pname})" = "" ]; then
			#    echo "MKNTFS On $pname Partition"
			#    mkntfs -Q -L ${name} -v /dev/mapper/${pname}
			#    mount /dev/mapper/${pname} /vz/mounts/${pname}
			#    if [ "$(mount | grep /vz/mounts/${pname})" = "" ]; then
			#     echo "Mounting problem"
			#    else
			#     echo "Rsyncing Data (Initial Sync)"
			#     rsync -a --delete --inplace --exclude desktop.ini --exclude Desktop.ini /vz/mounts/${tpname}/ /vz/mounts/${pname}/
			#     if [ -e /vz/mounts/${pname}/Windows/System32/config/SAM ]; then
			#      echo "Clearing Windows Password"
			#      /root/cpaneldirect/vps_kvm_setup_password_clear.expect ${pname} >/dev/null 2>&1
			#     fi
			#    fi
			#   else
			#    echo "Rsyncing Data (Quick Sync)"
			#    rsync -a --delete --inplace --exclude desktop.ini --exclude Desktop.ini /vz/mounts/${tpname}/ /vz/mounts/${pname}/
			#    if [ -e /vz/mounts/${pname}/Windows/System32/config/SAM ]; then
			#     echo "Clearing Windows Password"
			#     /root/cpaneldirect/vps_kvm_setup_password_clear.expect ${pname} >/dev/null 2>&1
			#    fi
			#   fi
			#   umount /vz/mounts/${pname}
			#   umount /vz/mounts/${tpname}
			#   echo "Copying Partition Boot Record $pname (dd)"
			#   dd if=/dev/mapper/${tpname} of=/dev/mapper/${pname} bs=512 count=1 >/dev/null 2>&1
			#  else
			#   echo "Copying Partition $pname (dd)"
			#   dd if=/dev/mapper/${tpname} of=/dev/mapper/${pname} >/dev/null 2>&1
			#  fi
			# done
			# /sbin/kpartx $kpartxopts -d /dev/vz/${template}
			# /sbin/kpartx $kpartxopts -d /dev/vz/${name}

			# /usr/bin/virsh setmaxmem ${name} ${memory};
			# /usr/bin/virsh setmem ${name} ${memory};
			# /usr/bin/virsh setvcpus ${name} ${vcpu};
		fi
		/usr/bin/virsh autostart ${name};
		mac="$(/usr/bin/virsh dumpxml ${name} |grep 'mac address' | cut -d\' -f2)";
		/bin/cp -f ${DHCPVPS} ${DHCPVPS}.backup;
		grep -v -e "host ${name} " -e "fixed-address $ip;" ${DHCPVPS}.backup > ${DHCPVPS}
		echo "host ${name} { hardware ethernet $mac; fixed-address $ip;}" >> ${DHCPVPS}
		rm -f ${DHCPVPS}.backup;
		if [ ! -e /etc/init.d/dhcpd ] && [ -e /etc/init.d/isc-dhcp-server ]; then
			/etc/init.d/isc-dhcp-server restart
		elif [ -e /etc/init.d/dhcpd ]; then
			/etc/init.d/dhcpd restart
		else
			service dhcpd restart;
		fi
		curl --connect-timeout 60 --max-time 600 -k -d action=install_progress -d progress=starting -d server=${name} "$url" 2>/dev/null
		/usr/bin/virsh start ${name};
		#/usr/bin/virsh resume ${template};
		bash /root/cpaneldirect/run_buildebtables.sh;
		if [ ! -d /cgroup/blkio/libvirt/qemu ]; then
			echo "CGroups Not Detected, Bailing";
		else
			slices="$(echo $memory / 1000 / 512 |bc -l | cut -d\. -f1)";
			cpushares="$(($slices * 512))";
			ioweight="$(echo "400 + (37 * $slices)" | bc -l | cut -d\. -f1)";
			echo "$vps$(printf %$((15-${#name}))s)${cpushares} Mb$(printf %$((11-${#cpushares}))s) = ${slices}$(printf %$((2-${#slices}))s) Slices -----> IO: $ioweight$(printf %$((6-${#ioweight}))s)CPU: $cpushares";
			virsh schedinfo ${name} --set cpu_shares=$cpushares --current;
			virsh schedinfo ${name} --set cpu_shares=$cpushares --config;
			virsh blkiotune ${name} --weight $ioweight --current;
			virsh blkiotune ${name} --weight $ioweight --config;
		fi;
		/root/cpaneldirect/run_buildebtables.sh
		/root/cpaneldirect/tclimit $ip;
		vnc="$((5900 + $(virsh vncdisplay $name | cut -d: -f2 | head -n 1)))";
		if [ "$vnc" == "" ]; then
			sleep 2s;
			vnc="$((5900 + $(virsh vncdisplay $name | cut -d: -f2 | head -n 1)))";
			if [ "$vnc" == "" ]; then
				sleep 2s;
				vnc="$(virsh dumpxml $name |grep -i "graphics type='vnc'" | cut -d\' -f4)";
			fi;
		fi;
		/root/cpaneldirect/vps_kvm_setup_vnc.sh $name "$clientip";
		/root/cpaneldirect/vps_kvm_screenshot.sh "$(($vnc - 5900))" "$url?action=screenshot&name=$name";
		/root/cpaneldirect/vps_kvm_screenshot.sh "$(($vnc - 5900))" "$url?action=screenshot&name=$name";
		#vnc="$(virsh dumpxml $name |grep -i "graphics type='vnc'" | cut -d\' -f4)";
		sleep 1s;
		/root/cpaneldirect/vps_kvm_screenshot.sh "$(($vnc - 5900))" "$url?action=screenshot&name=$name";
		sleep 2s;
		/root/cpaneldirect/vps_kvm_screenshot.sh "$(($vnc - 5900))" "$url?action=screenshot&name=$name";
		/admin/kvmenable blocksmtp $name
	fi
fi;
