#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
name=$1
myip=$(ifconfig eth0 | grep "inet addr" | cut -d: -f2| cut -d" " -f1)
ip=$2
if [ -e /etc/dhcp/dhcpd.vps ]; then
	DHCPVPS=/etc/dhcp/dhcpd.vps
else
	DHCPVPS=/etc/dhcpd.vps
fi
if [ $# -ne 2 ]; then
 echo "Create a New KVM"
 echo " - Creates LVM"
 echo " - Clones Windows VPS/LVM"
 echo " - Rebuild DHCPD"
 echo " - Startup"
 echo "Syntax $0 [name] [ip]"
 echo " ie $0 windows1337 1.2.3.4"
#check if vps exists
elif /usr/bin/virsh dominfo $name >/dev/null 2>&1; then
 echo "VPS $name already exists!";
else
 virsh vol-create-as --pool vz --name $name --capacity 100G
 /usr/bin/virsh suspend windows1
 virt-clone --force -o windows1 -n $name -f /dev/vz/$name
 /usr/bin/virsh resume windows1
 /usr/bin/virsh autostart $name
 mac=\"$(/usr/bin/virsh dumpxml $name |grep 'mac address' | cut -d\' -f2)\" 
 /bin/cp -f ${DHCPVPS} ${DHCPVPS}.backup 
 grep -v -e \"host $name \" -e \"fixed-address $ip;\" ${DHCPVPS}.backup > ${DHCPVPS} 
 echo \"host $name { hardware ethernet \$mac; fixed-address $ip;}\" >> ${DHCPVPS} 
 rm -f ${DHCPVPS}.backup 
 if [ ! -e /etc/init.d/dhcpd ] && [ -e /etc/init.d/isc-dhcp-server ]; then
  /etc/init.d/isc-dhcp-server restart
 else
  /etc/init.d/dhcpd restart
 fi
 /root/cpaneldirect/run_buildebtables.sh
 /root/cpaneldirect/tclimit $ip
 /usr/bin/virsh start $name;
 bash /root/cpaneldirect/run_buildebtables.sh;
fi
