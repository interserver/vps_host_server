#!/bin/bash
name=testcent6;
virsh destroy $name ; 
virsh undefine $name; 
lvremove -f /dev/vz/$name; 
lvcreate -L 25G -n $name vz; 
virt-install \
--hvm \
--name=$name \
--ram=1024 \
--location=http://mirror.trouble-free.net/centos/6.8/os/x86_64/ \
--os-type=Linux \
--os-variant=centos6.5 \
--network model=virtio,bridge=br0,mac=52:54:00:9c:94:3b \
--disk /dev/vz/$name,bus=virtio \
--vcpus=2 \
--initrd-inject=centos63.ks \
--extra-args="ks=file:/centos63.ks console=tty0 console=ttyS0,115200n8" \
--nographics 
