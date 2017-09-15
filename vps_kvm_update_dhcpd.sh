#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin";
if [ "$(crontab -l|grep qs_cron)" != "" ]; then
	url="https://myquickserver2.interserver.net/qs_queue.php"
else
	url="https://myvps2.interserver.net/vps_queue.php";
fi
curl --connect-timeout 300 --max-time 600 -k -d action=getvpsmainips "$url" 2>/dev/null | sh;
if [ -e /root/cpaneldirect/vps.mainips ]; then
	IFS="
";
	if [ -e /etc/dhcp/dhcpd.vps ]; then
		DHCPVPS=/etc/dhcp/dhcpd.vps;
		/bin/rm -f /etc/dhcpd.vps;
		ln -s /etc/dhcp/dhcpd.vps /etc/dhcpd.vps;
	else
		DHCPVPS=/etc/dhcpd.vps;
	fi;
	if [ -e ${DHCPVPS} ]; then
		/bin/mv -f ${DHCPVPS} ${DHCPVPS}.backup;
	fi;
	for user in $(virsh list | grep running | awk '{print $2}' |grep -v "^guestfs-"); do
		mac=`virsh dumpxml $user | grep "mac" | grep address | grep : | cut -d\' -f2`;
		id="$(echo "$user" | sed s#"[[:alpha:]]"#""#g)"
		ip="$(grep "^$id:" /root/cpaneldirect/vps.mainips | cut -d: -f2-)";
		echo "host $user { hardware ethernet $mac; fixed-address $ip;}" >> ${DHCPVPS};
	done;
	if [ ! -e /etc/init.d/dhcpd ] && [ -e /etc/init.d/isc-dhcp-server ]; then
		/etc/init.d/isc-dhcp-server restart;
	elif [ -e /etc/init.d/dhcpd ]; then
		/etc/init.d/dhcpd restart;
	else
		service dhcpd restart;
	fi;
fi;