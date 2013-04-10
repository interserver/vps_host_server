#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
set -x
if [ "$(kpartx 2>&1 |grep sync)" = "" ]; then
	kpartxopts=""
else
	kpartxopts="-s"
fi
name=$1
pass=$2
if [ $# -ne 2 ]; then
 echo "Set VPS Password"
 echo "Syntax $0 [name] [pass]"
 echo " ie $0 windows1337 pass123"
#check if vps exists
elif ! virsh dominfo ${name} >/dev/null 2>&1; then
 echo "VPS ${name} doesnt exists!";
else
 virsh destroy ${name}
 echo "Creating Partition Table Links" && \
 /sbin/kpartx $kpartxopts -av /dev/vz/${name} && \
 if [ -e "/dev/mapper/vz-${name}p1" ]; then
  pname="vz-${name}"
 else
  pname="$name"
 fi && \
 mkdir -p /vz/mounts/${pname}p2 && \
 echo "Mounting Partition"
 if [ -e /dev/mapper/${pname}p2 ]; then
  mount /dev/mapper/${pname}p2 /vz/mounts/${name}p2
 else
  mount /dev/mapper/${pname}p1 /vz/mounts/${name}p2
 fi
 echo "Setting Password"
 /root/cpaneldirect/vps_kvm_setup_password.expect $name $pass
break;
 echo "Saving Changes"
 umount /vz/mounts/${name}p2 2>/dev/null
 /sbin/kpartx $kpartxopts -dv /dev/vz/${name}
 echo "Starting VPS"
 virsh start ${name};
fi
