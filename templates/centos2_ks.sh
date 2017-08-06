#!/bin/bash
virt-install \
-n centos \
-r 2048 \
--vcpus=1 \
--os-variant=rhel5.4 \
--accelerate \
-v \
-w bridge:br0 \
-w bridge:br1 \
--disk path=/emc/kvm/centos.img,size=100 \
-l http://mirrors.nixcraft.in/centos/5.5/os/x86_64/ \
-nographics \
-x "ks=http://10.10.21.3/static/ks.cfg ksdevice=eth0 ip=10.10.21.76 netmask=255.255.255.240 dns=10.10.21.1 gateway=10.10.21.100"
