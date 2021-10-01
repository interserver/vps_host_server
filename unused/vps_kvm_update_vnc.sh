#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
export base="$(readlink -f "$(dirname "$0")")";
myip="$(ifconfig $(ip route list | grep "^default" | sed s#"^default.*dev "#""#g | cut -d" " -f1)  |grep inet |grep -v inet6 | awk '{ print $2 }' | cut -d: -f2)"
name=$1
if [ $# -ne 1 ]; then
 echo "Open VNC To IP"
 echo " Setup xinetd to allow VNC access from an IP masq for a specific VPN"
 echo "Syntax $0 [vps]"
 echo " ie $0 windows1"
#check if vps exists
elif ! virsh dominfo $name >/dev/null 2>&1; then
 echo "Invalid VPS $name";
else
 port="$(virsh dumpxml $name | grep vnc |grep port= | cut -d\' -f4)"
 if [ "$port" != "" ]; then
  cat ${base}/vps_kvm_xinetd.template | \
  sed s#"NAME"#"$name"#g | \
  sed s#"MYIP"#"$myip"#g | \
  sed s#"IP"#"$ip"#g | \
  sed s#"PORT"#"$port"#g > /etc/xinetd.d/$name
  echo "VNC Server $myip Port $port For VPS $name Opened To IP $ip"
 else
  echo "no vnc port found for $myip"
 fi
 port="$(virsh dumpxml $name | grep spice |grep port= | cut -d\' -f4)"
 if [ "$port" != "" ]; then
  cat ${base}/vps_kvm_xinetd.template | \
  sed s#"NAME"#"$name"#g | \
  sed s#"MYIP"#"$myip"#g | \
  sed s#"IP"#"$ip"#g | \
  sed s#"PORT"#"$port"#g > /etc/xinetd.d/${name}-spice
  echo "Spice Server $myip Port $port For VPS $name Opened To IP $ip"
 else
  echo "no spice port found for $myip, skipping"
 fi
 if [ -e /etc/init.d/xinetd ]; then
  /etc/init.d/xinetd reload >/dev/null 2>&1
 else
  service xinetd reload >/dev/null 2>&1
 fi;
fi

