#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
set -x
if [ "$(kpartx 2>&1 |grep sync)" = "" ]; then
	kpartxopts=""
else
	kpartxopts="-s"
fi
name=$1
if [ $# -ne 1 ]; then
 echo "Removew VPS"
 echo " - Suspend Processes"
 echo " - Remove from config"
 echo " - Remove LVM"
 echo "Syntax $0 [name]"
 echo " ie $0 windows1337"
#check if vps exists
else
 if ! virsh dominfo $name >/dev/null 2>&1; then
  echo "VPS $name doesnt exist!";
 else
  echo "Removing and Stoping VPS"
  virsh destroy $name
  virsh undefine $name
 fi
 if [ -e /dev/vz/$name ]; then
  echo "Removing LVM"
  /sbin/kpartx $kpartxopts -dv /dev/vz/$name
  echo y | lvremove /dev/vz/$name
 fi
 if [ ! "$(grep "host ${name} {" /etc/dhcpd.vps)" = "" ]; then
  echo "Removing DHCP"
  mv -f /etc/dhcpd.vps /etc/dhcpd.vps.backup && \
  grep -v -e "host ${name} " /etc/dhcpd.vps.backup > /etc/dhcpd.vps && \
  rm -f /etc/dhcpd.vps.backup && \
  /etc/init.d/dhcpd restart
 fi
fi

