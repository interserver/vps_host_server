#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
set -x
if [ "$(kpartx 2>&1 |grep sync)" = "" ]; then
	kpartxopts=""
else
	kpartxopts="-s"
fi
name=$1
if [ -e /etc/dhcp/dhcpd.vps ]; then
	DHCPVPS=/etc/dhcp/dhcpd.vps
else
	DHCPVPS=/etc/dhcpd.vps
fi
if [ $# -ne 1 ]; then
 echo "Removew VPS"
 echo " - Suspend Processes"
 echo " - Remove from config"
 echo " - Remove LVM"
 echo "Syntax $0 [name]"
 echo " ie $0 windows1337"
#check if vps exists
else
 virsh managedsave-remove $name;
 if ! virsh dominfo $name >/dev/null 2>&1; then
  echo "VPS $name doesn't exist!";
 else
  echo "Removing and Stoping VPS"
  virsh destroy $name
  virsh undefine $name
 fi
 if [ -e /dev/vz/$name ]; then
  echo "Removing LVM"
  /sbin/kpartx $kpartxopts -dv /dev/vz/$name
  lvremove /dev/vz/$name -f
 fi
 if [ ! "$(grep "host ${name} {" ${DHCPVPS})" = "" ]; then
  echo "Removing DHCP"
  /bin/cp -f ${DHCPVPS} ${DHCPVPS}.backup && \
  grep -v -e "host ${name} " ${DHCPVPS}.backup > ${DHCPVPS} && \
  rm -f ${DHCPVPS}.backup && \
  if [ ! -e /etc/init.d/dhcpd ] && [ -e /etc/init.d/isc-dhcp-server ]; then
   /etc/init.d/isc-dhcp-server restart
  else
   /etc/init.d/dhcpd restart
  fi
 fi
fi

