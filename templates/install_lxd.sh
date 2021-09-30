#!/bin/bash
vps=vps230617
myip=70.44.33.193
root="$2"
ip=162.250.126.182
template="$1"
mac="00:16:3e:03:84:d9"

export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/usr/sbin:/sbin:/bin:/usr/bin";
lxc stop -f ${vps};
lxc delete -f ${vps};

cp -f /etc/lxc/dnsmasq.conf /etc/lxc/dnsmasq.conf.backup;
cat /etc/lxc/dnsmasq.conf.backup |grep -v -e ",${ip}$" -e "=${mac}," -e "=${vps}," > /etc/lxc/dnsmasq.conf;
echo "dhcp-host=${mac},${ip}" >> /etc/lxc/dnsmasq.conf;
killall -HUP dnsmasq
lxc init "images:$template" ${vps}
lxc config set ${vps} limits.memory 2048MB;
lxc config set ${vps} limits.cpu 2;
lxc config set ${vps} volatile.eth0.hwaddr ${mac};
lxc network attach br0 ${vps} eth0
lxc config device set ${vps} eth0 ipv4.address ${ip}
lxc config device set ${vps} eth0 security.mac_filtering true
lxc config device add ${vps} root disk path=/ pool=lxd size=30720GB;
lxc start ${vps} || lxc info --show-log ${vps}
lxc exec ${vps} -- bash -c 'x=0; while [ 0 ]; do x=$(($x + 1)); ping -c 2 4.2.2.2; if [ $? -eq 0 ] || [ "$x" = "20" ]; then break; else sleep 1s; fi; done'
lxc exec ${vps} -- bash -c "echo ALL: ALL >> /etc/hosts.allow;"
lxc exec ${vps} -- bash -c "if [ -e /etc/apt ]; then apt update; apt install openssh-server -y; fi;"
lxc exec ${vps} -- bash -c "if [ -e /etc/yum ]; then yum install openssh-server -y; fi;"
lxc exec ${vps} -- sed s#"^\#*PermitRootLogin .*$"#"PermitRootLogin yes"#g -i /etc/ssh/sshd_config;
lxc exec ${vps} -- bash -c "echo root:\"${root}\" | chpasswd"
lxc exec ${vps} -- /etc/init.d/ssh restart;
lxc exec ${vps} -- /etc/init.d/sshd restart;
lxc exec ${vps} -- systemctl restart sshd;
lxc exec ${vps} -- locale-gen --purge en_US.UTF-8
lxc exec ${vps} -- bash -c "echo -e 'LANG=\"en_US.UTF-8\"\nLANGUAGE=\"en_US:en\"\n' > /etc/default/locale"

found=0
c=0
cMax=20;
while [ $found -eq 0 ] && [ $c -le ${cMax} ]; do
	echo "[${c}/${cMax}] "
        ping ${ip} -c 1 && found=1
        c=$(($c + 1))
done
echo "$template Found $found after $c" | tee -a test.log
if [ $found -eq 1 ]; then
    ssh-keygen -f ~/.ssh/known_hosts -R ${ip}
    sleep 10s
    if /root/cpaneldirect/templates/test_ssh.expect ${ip} root "${root}"; then
        echo "$template Good Login" | tee -a test.log
    else
        echo "$template Failed Login" | tee -a test.log
    fi
fi

