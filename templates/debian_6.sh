#!/bin/bash
virt-install \
--name debian6 \
--ram 1024 \
--disk path=./debian6.qcow2,size=8 \
--vcpus 1 \
--os-type linux \
--os-variant debian6 \
--network bridge=virbr0 \
--graphics none \
--console pty,target_type=serial \
--location 'http://ftp.nl.debian.org/debian/dists/squeeze/main/installer-amd64/' \
--extra-args 'console=ttyS0,115200n8 serial'
