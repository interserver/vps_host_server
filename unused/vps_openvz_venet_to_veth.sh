#!/bin/bash
# This Script will move an IP from venet to veth
# By Joe Huss <detain@interserver.net>

export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/bin:/usr/bin:/sbin:/usr/sbin"
export base="$(readlink -f "$(dirname "$0")")";
set -x

if [ $# -ne 1 ]; then
	echo "Invalid Arguments"
	echo ""
	echo "Syntax:";
	echo "	$0 <VZID>"
	echo ""
	echo "Will convert the IPs on a venet VPS to veth"
	exit;
fi

# load some required modules
modprobe vznetdev
modprobe vzethdev
vz=$1
. /etc/vz/conf/${vz}.conf
realnet=eth0
vnet="veth${vz}.0"
ips="$IP_ADDRESS";
#mastermac="$(/sbin/ifconfig $realnet | grep HWaddr | awk '{ print $5 }')"
#echo -e "Getting Master Mac $mastermac\n"
#newmac="$(${base}/easymac.sh -R -m | grep -v "^$")"
#echo -e "Getting New Mac $newmac\n"
/usr/sbin/vzctl stop ${vz}
/usr/sbin/vzctl set $vz --ipdel all --save
#for ip in $ips; do
#	echo "Got OpenVZ System $vz IP $ip"
#	/usr/sbin/vzctl set ${vz} --netif_add "${realnet},${newmac},${vnet},${mastermac}" --save
#done
/usr/sbin/vzctl set ${vz} --netif_add "${realnet}" --save
/usr/sbin/vzctl restart ${vz}
while ! ifconfig ${vnet}; do
	sleep 1s
done >/dev/null 2>&1
/sbin/ifconfig ${vnet} 0
/usr/sbin/vzctl exec ${vz} "/sbin/ifconfig ${realnet} 0"
/usr/sbin/vzctl exec ${vz} "/sbin/ip route add default dev ${realnet}"
echo 1 > /proc/sys/net/ipv4/conf/${vnet}/forwarding
echo 1 > /proc/sys/net/ipv4/conf/${vnet}/proxy_arp
echo 1 > /proc/sys/net/ipv4/conf/${realnet}/forwarding
echo 1 > /proc/sys/net/ipv4/conf/${realnet}/proxy_arp
for ip in $ips; do
	/usr/sbin/vzctl exec ${vz} "/sbin/ip addr add ${ip} dev ${realnet}"
	/sbin/ip route add ${ip} dev ${vnet}
done


