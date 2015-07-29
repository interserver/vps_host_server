#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
set -x
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
url="https://myquickserver2.interserver.net/qs_queue.php"
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
if [ $# -lt 3 ]; then
 echo "Create a New KVM"
 echo " - Creates LVM"
 echo " - Clones Windows VPS/LVM"
 echo " - Rebuild DHCPD"
 echo " - Startup"
 echo "Syntax $0 <name> <ip> <template> [diskspace] [memory] [vcpu]"
 echo " ie $0 windows1337 1.2.3.4 windows1"
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
 fi
 mv -f ${name}.xml ${name}.xml.backup
 cat ${name}.xml.backup | sed s#"<\(vcpu.*\)>.*</vcpu>"#"<\1>${vcpu}</vcpu>"#g | sed s#"<memory.*memory>"#"<memory>${memory}</memory>"#g | sed s#"<currentMemory.*currentMemory>"#"<currentMemory>${memory}</currentMemory>"#g > ${name}.xml
  if [ ! -e /usr/libexec/qemu-kvm ] && [ -e /usr/bin/kvm ]; then
   sed s#"rhel5.5.0"#"pc-1.0"#g -i ${name}.xml
   sed s#"/usr/libexec/qemu-kvm"#"/usr/bin/kvm"#g -i ${name}.xml
  fi

 rm -f ${name}.xml.backup
 sed s#"<target dev='hda' bus='ide'/>"#"<target dev='vda' bus='virtio'/>"#g -i ${name}.xml
 mv -f ${name}.xml ${name}.xml.backup
 grep -v "address type='drive'" ${name}.xml.backup > ${name}.xml
 rm -f ${name}.xml.backup
 /usr/bin/virsh define ${name}.xml
 if [ "$template" = "windows1" ]; then
  template=windows2
 fi
 if [ -e "/${template}.img.gz" ]; then
  echo "Copying $template Image"
  gzip -dc "/${template}.img.gz"  | dd of=/dev/vz/${name} 2>&1 &
  #pid="$(pidof gzip)"
  pid=$!
  echo "Got gzip PID $pid";
  if [ "$(pidof gzip)" != "" ]; then
   pid="$(pidof gzip)"
   echo "Tried again, got gzpi PID $pid"
  fi
  if [ "$(echo "$pid" | grep " ")" != "" ]; then
   pid=$(pgrep -f 'gzip -dc')
   echo "Didnt like gzip pid (had a space?), going with gzip PID $pid"
  fi
  tsize=$(stat -L /proc/$pid/fd/3 -c "%s")
  while [ -d /proc/$pid ]; do
	copied=$(awk '/pos:/ { print $2 }' /proc/$pid/fdinfo/3)
	completed="$(echo "$copied/$tsize*100" |bc -l | cut -d\. -f1)"
	curl --connect-timeout 60 --max-time 240 -k -d action=install_progress -d progress=${completed} -d server=${name} "$url" 2>/dev/null
	if [ "$(grep -v idle /sys/block/md*/md/sync_action 2>/dev/null)" != "" ]; then
		softraid="$(grep -l -v idle /sys/block/md*/md/sync_action 2>/dev/null)"
		for softfile in $softraid; do
			echo idle > $softfile
		done
	fi		
	echo "$completed%"
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
	  curl --connect-timeout 60 --max-time 240 -k -d action=install_progress -d progress=${completed} -d server=${name} "$url" 2>/dev/null
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
	  curl --connect-timeout 60 --max-time 240 -k -d action=install_progress -d progress=${completed} -d server=${name} "$url" 2>/dev/null
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
 curl --connect-timeout 60 --max-time 240 -k -d action=install_progress -d progress=resizing -d server=${name} "$url" 2>/dev/null
 sects="$(fdisk -l -u /dev/vz/${name}  | grep -e "total .* sectors$" | sed s#".*total \(.*\) sectors$"#"\1"#g)"
 t="$(fdisk -l -u /dev/vz/${name} | sed s#"\*"#""#g | grep "^/dev/vz" | tail -n 1)"
 p="$(echo $t | awk '{ print $1 }')"
 fs="$(echo $t | awk '{ print $5 }')"
 pn="$(echo "$p" | sed s#"/dev/vz/${name}p"#""#g)"
 start="$(echo $t | awk '{ print $2 }')"
 if [ "$fs" = "83" ]; then
  echo "Resizing Last Partition To Use All Free Space"
  echo -e "d
$pn
n
p
$pn
$start


w
print
q
" | fdisk -u /dev/vz/${name}
  kpartx $kpartxopts -av /dev/vz/${name}
if [ -e "/dev/mapper/vz-${name}p${pn}" ]; then
 pname="vz-${name}"
else
 pname="$name"
fi
  fsck -T -p -f /dev/mapper/${pname}p${pn}
  if [ -f "$(which resize4fs 2>/dev/null)" ]; then 
   resizefs="resize4fs"
  else
   resizefs="resize2fs"
  fi
  $resizefs -p /dev/mapper/${pname}p${pn}
  mkdir -p /vz/mounts/${name}p${pn}
  mount /dev/mapper/${pname}p${pn} /vz/mounts/${name}p${pn}; 
  PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin" echo "root:${password}" | chroot /vz/mounts/${name}p${pn} chpasswd
  umount /dev/mapper/${pname}p${pn}
  kpartx $kpartxopts -d /dev/vz/${name}
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
 /usr/bin/virsh autostart ${name}
 mac="$(/usr/bin/virsh dumpxml ${name} |grep 'mac address' | cut -d\' -f2)"
 /bin/cp -f $DHCPVPS ${DHCPVPS}.backup
 grep -v -e "host ${name} " -e "fixed-address $ip;" ${DHCPVPS}.backup > $DHCPVPS
 echo "host ${name} { hardware ethernet $mac; fixed-address $ip;}" >> $DHCPVPS
 rm -f ${DHCPVPS}.backup
 if [ ! -e /etc/init.d/dhcpd ] && [ -e /etc/init.d/isc-dhcp-server ]; then
  /etc/init.d/isc-dhcp-server restart
 else
  /etc/init.d/dhcpd restart
 fi
 curl --connect-timeout 60 --max-time 240 -k -d action=install_progress -d progress=starting -d server=${name} "$url" 2>/dev/null
 /usr/bin/virsh start ${name};
 /root/cpaneldirect/vps_refresh_vnc.sh $name

 #/usr/bin/virsh resume ${template}
 /root/cpaneldirect/run_buildebtables.sh
 /scripts/tclimit $ip
 vnc="$(virsh dumpxml $name |grep -i "graphics type='vnc'" | cut -d\' -f4)"
 /root/cpaneldirect/vps_kvm_screenshot.sh $(($vnc - 5900)) "$url?action=screenshot&name=$name" 
fi
