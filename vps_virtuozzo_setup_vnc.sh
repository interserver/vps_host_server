#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
export base="$(readlink -f "$(dirname "$0")")";
name=$1
myip="$(ifconfig $(ip route list | grep "^default" | sed s#"^default.*dev "#""#g | cut -d" " -f1)  |grep inet |grep -v inet6 | awk '{ print $2 }' | cut -d: -f2)"
ip=$2
if [ $# -ne 2 ]; then
 echo "Open VNC To IP"
 echo " Setup xinetd to allow VNC access from an IP masq for a specific VPN"
 echo "Syntax $0 [vps] [ip]"
 echo " ie $0 vps12322 4.2.2.2"
#check if vps exists
elif ! prlctl status $name >/dev/null 2>&1; then
 echo "Invalid VPS $name";
else
 name="$(prlctl list $name -i |grep EnvID|cut -d" " -f2)"
 port="$(prlctl list $name -i |grep "Remote display.*port=" | sed s#"^.*port=\([0-9]*\) .*$"#"\1"#g)"
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
 service xinetd restart
fi

