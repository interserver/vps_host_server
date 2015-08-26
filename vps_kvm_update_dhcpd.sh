#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin";
IFS="
";
if [ -e /etc/dhcp/dhcpd.vps ]; then
	DHCPVPS=/etc/dhcp/dhcpd.vps;
	/bin/rm -f /etc/dhcpd.vps;
	ln -s /etc/dhcp/dhcpd.vps /etc/dhcpd.vps;
else
	DHCPVPS=/etc/dhcpd.vps;
fi;
touch ${DHCPVPS};
for user in $(/usr/bin/virsh list | grep running | awk '{print $2}'); do
	mac=`/usr/bin/virsh dumpxml $user | grep "mac" | grep address | grep : | cut -d\' -f2`;
	cat ${DHCPVPS} | sed s#"host $user { hardware ethernet .*; fix"#"host $user { hardware ethernet $mac; fix"#g > ${DHCPVPS}.new;
	cat ${DHCPVPS}.new > ${DHCPVPS};
	rm -f ${DHCPVPS}.new ${DHCPVPS};
done;
if [ ! -e /etc/init.d/dhcpd ] && [ -e /etc/init.d/isc-dhcp-server ]; then
	/etc/init.d/isc-dhcp-server restart;
elif [ -e /etc/init.d/dhcpd ]; then
	/etc/init.d/dhcpd restart;
else
	service dhcpd restart;
fi;
