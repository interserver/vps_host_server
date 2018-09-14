#!/bin/bash
base="$(readlink -f "$(dirname "$0")")";
# set HISTFILESIZE and HISTSIZE to unlimited
sed s#"^\(HIST.*SIZE\)=.*$"#"\1="#g -i /root/.bashrc
# load ubuntu distro version variables
. /etc/lsb-release
# replace /etc/apt/sources.list with condensed and in most cases better version
echo "
deb http://us.archive.ubuntu.com/ubuntu/ ${DISTRIB_CODENAME} main restricted universe multiverse
deb http://us.archive.ubuntu.com/ubuntu/ ${DISTRIB_CODENAME}-updates main restricted universe multiverse
deb http://us.archive.ubuntu.com/ubuntu/ ${DISTRIB_CODENAME}-backports main restricted universe multiverse
deb http://us.archive.ubuntu.com/ubuntu/ ${DISTRIB_CODENAME}-proposed main restricted universe multiverse
deb http://security.ubuntu.com/ubuntu ${DISTRIB_CODENAME}-security main restricted universe multiverse
deb http://archive.canonical.com/ubuntu ${DISTRIB_CODENAME} partner

deb-src http://us.archive.ubuntu.com/ubuntu/ ${DISTRIB_CODENAME} main restricted universe multiverse
deb-src http://us.archive.ubuntu.com/ubuntu/ ${DISTRIB_CODENAME}-updates main restricted universe multiverse
deb-src http://us.archive.ubuntu.com/ubuntu/ ${DISTRIB_CODENAME}-backports main restricted universe multiverse
deb-src http://us.archive.ubuntu.com/ubuntu/ ${DISTRIB_CODENAME}-proposed main restricted universe multiverse
deb-src http://security.ubuntu.com/ubuntu ${DISTRIB_CODENAME}-security main restricted universe multiverse
deb-src http://archive.canonical.com/ubuntu ${DISTRIB_CODENAME} partner
" > /etc/apt/sources.list;
# update system
apt-get update;
apt-get autoremove -y;
# ensure we dont have lxc (installed by default) related packages floating around
apt-get remove -y --purge lxd lxd-client  lxcfs liblxc-common liblxc1;
pkgs="
bash-completion
command-not-found
imagemagick-6.q16
ioping
isc-dhcp-server
libmodule-pluggable-perl
libmonitoring-plugin-perl;
libvirt-clients
libvirt-daemon-driver-storage-zfs
libvirt-dev
libzfs2linux
libzfslinux-dev
nano
netplan.io
php-cli
qemu
qemu-kvm
qemu-system-misc
qemu-system-x86
qemu-user
qemu-user-binfmt
sensible-mda
sysstat
unattended-upgrades
virt-goodies
virtinst
virt-top
xinetd
zfs-dkms
zfs-initramfs
zfsutils-linux
zfs-zed
"
# install some required packages
apt-get install -y $pkgs
# update system to latest packages
apt-get dist-upgrade -y;
# remove outdated packages/kernels
apt-get autoremove -y;
cd ${base}
# setup libvirt zfs / lvm storage pool
./create_libvirt_storage_pools.sh
cd ${base}/workerman
# install some additional prerequisites
./update.sh
apt-get autoremove -y;
apt-get clean;
ldconfig;
updatedb;
