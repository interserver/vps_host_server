auth  --useshadow  --enablemd5
bootloader --location=mbr
zerombr
clearpart --all --initlabel
text
firewall --enabled --port=22:tcp
firstboot --disable
keyboard us
network --device eth0 --bootproto static --ip 10.10.21.76 --netmask 255.255.255.240 --gateway 10.10.21.100 --nameserver 10.10.21.1,10.10.21.2 --noipv6
network --device eth1 --bootproto static --ip 123.1.2.6 --netmask 255.255.255.240 --gateway 123.1.2.100 --nameserver 10.10.21.1,10.10.21.2 --hostname centos.nixcraft.in --noipv6
lang en_US
logging --level=info
url --url=http://mirrors.nixcraft.in/centos/5.5/os/x86_64/ 
reboot
rootpw --iscrypted $1$somepassword
selinux --enforcing
skipx
timezone  America/New_York
install
part / --bytes-per-inode=4096 --fstype="ext3" --grow --size=1
part swap --recommended
%packages
@core
--nobase
%post
(
echo '10.0.0.0/8 via 10.10.21.100' > /etc/sysconfig/network-scripts/route-eth0
sed -i 's/LABEL=\//& console=ttyS0/' /etc/grub.conf
echo 'S0:12345:respawn:/sbin/agetty ttyS0 115200' >> /etc/inittab
echo "ttyS0" >> /etc/securetty
echo 'IPV6INIT=no' >> /etc/sysconfig/network
echo 'install ipv6 /bin/true' >> /etc/modprobe.conf
) 1>/root/post_install.log 2>&1
