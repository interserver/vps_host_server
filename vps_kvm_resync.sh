#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
set -x
name=$1
if [ $# -ne 1 ]; then
 echo "Re-Syncs A VPS"
 echo "Syntax $0 [name]"
 echo " ie $0 windows1337"
#check if vps exists
elif ! virsh dominfo ${name} >/dev/null 2>&1; then
 echo "VPS ${name} doesnt exists!";
else
 virsh suspend windows1
 virsh destroy ${name}
 /sbin/kpartx -av /dev/vz/windows1 && \
 /sbin/kpartx -av /dev/vz/${name} && \
 mkdir -p /vz/mounts/windows1p2 && \
 mkdir -p /vz/mounts/${name}p2 && \
 mount /dev/mapper/windows1p2 /vz/mounts/windows1p2 && \
 mount /dev/mapper/${name}p2 /vz/mounts/${name}p2 && \
 rsync -a --delete /vz/mounts/windows1p2/ /vz/mounts/${name}p2/
 umount /vz/mounts/windows1p2 2>/dev/null
 umount /vz/mounts/${name}p2 2>/dev/null
 /sbin/kpartx -dv /dev/vz/windows1
 /sbin/kpartx -dv /dev/vz/${name}
 virsh start ${name};
 virsh resume windows1
fi
