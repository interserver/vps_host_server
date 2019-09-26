#!/bin/bash
vps=vps230617
myip=70.44.33.193
root="$2"
ip=162.250.126.182
template="$1"
mac="00:16:3e:03:84:d9"

export PATH="$PATH:/usr/sbin:/sbin:/bin:/usr/bin:";
lxc stop -f ${vps};
lxc delete -f ${vps};

cp -f /etc/lxc/dnsmasq.conf /etc/lxc/dnsmasq.conf.backup;
cat /etc/lxc/dnsmasq.conf.backup |grep -v -e ",${ip}$" -e "=${mac}," -e "=${vps}," > /etc/lxc/dnsmasq.conf;
echo "dhcp-host=${mac},${ip}" >> /etc/lxc/dnsmasq.conf;
killall -HUP dnsmasq
lxc init "images:$template" ${vps}
lxc config set ${vps} limits.memory 2048MB;
lxc config set ${vps} limits.cpu 1;
lxc config set ${vps} volatile.eth0.hwaddr ${mac};
lxc network attach br0 ${vps} eth0
lxc config device set ${vps} eth0 ipv4.address ${ip}
lxc config device set ${vps} eth0 security.mac_filtering true
lxc config device add ${vps} root disk path=/ pool=lxd size=30720GB;
lxc start ${vps}
lxc exec ${vps} -- bash -c "echo ALL: ALL >> /etc/hosts.allow;"
lxc exec ${vps} -- apt update;
lxc exec ${vps} -- apt install openssh-server -y ;
lxc exec ${vps} -- sed s#"^\#*PermitRootLogin .*$"#"PermitRootLogin yes"#g -i /etc/ssh/sshd_config;
lxc exec ${vps} -- systemctl restart sshd;"
lxc exec ${vps} -- echo root:'${root}' | chpasswd;
lxc exec ${vps} -- locale-gen --purge en_US.UTF-8 && \
lxc exec ${vps} -- bash -c "echo -e 'LANG=\"en_US.UTF-8\"\nLANGUAGE=\"en_US:en\"\n' > /etc/default/locale"

