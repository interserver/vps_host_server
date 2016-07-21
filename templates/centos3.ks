#version=RHEL7
# Action
install

# System authorization information
auth --enableshadow --passalgo=sha512
repo --name="EPEL" --baseurl=http://dl.fedoraproject.org/pub/epel/7/x86_64

# Accept Eula
eula --agreed

reboot
# Use network installation
url --url="http://sunsite.rediris.es/mirror/CentOS/7/os/x86_64"
# Run the Setup Agent on first boot
firstboot --enable
ignoredisk --only-use=sda
# Keyboard layouts
keyboard --vckeymap=es --xlayouts='es'
# System language
lang en_US.UTF-8

# Network information
network  --bootproto=dhcp --device=enp0s3 --noipv6 --activate
network  --hostname=centos7.test.local
# Root password
rootpw --iscrypted SOMECRYPTEDPASSWORD
# System services
services --enabled=NetworkManager,sshd,chronyd
# System timezone
timezone Europe/Madrid --isUtc --ntpservers=0.centos.pool.ntp.org,1.centos.pool.ntp.org,2.centos.pool.ntp.org,3.centos.pool.ntp.org
user --groups=wheel --homedir=/home/marc --name=marc --password=SOMECRYPTEDPASSWORD --iscrypted --gecos="marc"
# System bootloader configuration
bootloader --location=mbr --boot-drive=sda
autopart --type=lvm
zerombr
# Partition clearing information
clearpart --all --drives=sda

# Selinux State
selinux --permissive

%packages
@base
@core
chrony
yum-cron
vim

%end

