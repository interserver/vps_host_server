install
lang en_GB.UTF-8
keyboard us
timezone Australia/Melbourne
auth --useshadow --enablemd5
selinux --disabled
firewall --disabled
services --enabled=NetworkManager,sshd
eula --agreed
ignoredisk --only-use=sda
reboot

bootloader --location=mbr
zerombr
clearpart --all --initlabel
part swap --asprimary --fstype="swap" --size=1024
part /boot --fstype xfs --size=200
part pv.01 --size=1 --grow
volgroup rootvg01 pv.01
logvol / --fstype xfs --name=lv01 --vgname=rootvg01 --size=1 --grow

rootpw --iscrypted $YOUR_ROOT_PASSWORD_HASH_HERE

repo --name=base --baseurl=http://mirror.cogentco.com/pub/linux/centos/7/os/x86_64/
url --url="http://mirror.cogentco.com/pub/linux/centos/7/os/x86_64/"

%packages --nobase --ignoremissing
@core
%end

