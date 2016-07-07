#!/bin/bash
virt-install \
--name centos5 \
--ram 1024 \
--disk path=./centos5.qcow2,size=8 \
--vcpus 1 \
--os-type linux \
--os-variant centos5 \
--network bridge=virbr0 \
--graphics none \
--console pty,target_type=serial \
--location 'http://mirror.i3d.net/pub/centos/5/os/x86_64/' \
--extra-args 'console=ttyS0,115200n8 serial'
