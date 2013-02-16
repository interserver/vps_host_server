#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
name=$1
size=$2
IFS="
"
for user in $(/usr/bin/virsh list | grep running | awk '{print $2}'); do
	mac=`/usr/bin/virsh dumpxml $user | grep "mac" | grep address | grep : | cut -d\' -f2`;
	cat /etc/dhcpd.vps | sed s#"host $user { hardware ethernet .*; fix"#"host $user { hardware ethernet $mac; fix"#g > /etc/dhcpd.vps.new
	/bin/mv -f /etc/dhcpd.vps.new /etc/dhcpd.vps
done
/etc/init.d/dhcpd restart
