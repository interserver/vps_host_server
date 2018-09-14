#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin";
export base="$(readlink -f "$(dirname "$0")")";

# Test an IP address for validity, Usage:
#      valid_ip IP_ADDRESS
#      if [[ $? -eq 0 ]]; then echo good; else echo bad; fi
#  or  if valid_ip IP_ADDRESS; then echo good; else echo bad; fi
function valid_ip(){
	local  ip=$1
	local  stat=1
	if [[ $ip =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]]; then
		OIFS=$IFS
		IFS='.'
		ip=($ip)
		IFS=$OIFS
		[[ ${ip[0]} -le 255 && ${ip[1]} -le 255 && ${ip[2]} -le 255 && ${ip[3]} -le 255 ]]
		stat=$?
	fi
	return $stat
}

if [ "$(crontab -l|grep qs_cron)" != "" ]; then
	url="https://myquickserver2.interserver.net/qs_queue.php"
else
	url="https://myvps2.interserver.net/vps_queue.php";
fi
curl --connect-timeout 300 --max-time 600 -k -d action=get_vps_main_ips "$url" 2>/dev/null | sh;
if [ -e ${base}/vps.mainips ]; then
	IFS="
";
	if [ -e /etc/dhcp/dhcpd.vps ]; then
		DHCPVPS=/etc/dhcp/dhcpd.vps;
		/bin/rm -f /etc/dhcpd.vps;
		ln -s /etc/dhcp/dhcpd.vps /etc/dhcpd.vps;
	elif [ -d /etc/dhcp ]; then
		mv -f /etc/dhcpd.vps /etc/dhcp/dhcpd.vps;
		ln -s /etc/dhcp/dhcpd.vps /etc/dhcpd.vps;
		DHCPVPS=/etc/dhcp/dhcpd.vps;
	else
		DHCPVPS=/etc/dhcpd.vps;
	fi;
	if [ -e ${DHCPVPS} ]; then
		/bin/mv -f ${DHCPVPS} ${DHCPVPS}.backup;
	fi;
	touch ${DHCPVPS};
	for user in $(virsh list | grep running | awk '{ print $2 }' |grep -v "^guestfs-"); do
		mac=`virsh dumpxml $user | grep "mac" | grep address | grep : | cut -d\' -f2`;
		id="$(echo "$user" | sed s#"[[:alpha:]]"#""#g)"
		# adding head -n 1 in case vps.mainips every has the same entry more than once
		ip="$(grep "^$user:" ${base}/vps.mainips | cut -d: -f2- | head -n 1)";
		if valid_ip $ip; then
				echo "host $user { hardware ethernet $mac; fixed-address $ip;}" >> ${DHCPVPS};
		#else
			#if [ -e ${DHCPVPS}.backup ]; then
				#/bin/mv -f ${DHCPVPS}.backup ${DHCPVPS};
				#break;
			#fi;
		fi;
	done;
	if [ ! -e /etc/init.d/dhcpd ] && [ -e /etc/init.d/isc-dhcp-server ]; then
		/etc/init.d/isc-dhcp-server restart;
	elif [ -e /etc/init.d/dhcpd ]; then
		/etc/init.d/dhcpd restart;
	else
		service dhcpd restart;
	fi;
fi;
