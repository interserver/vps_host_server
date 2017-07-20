#!/bin/bash
virt-install \
--name ubuntu1604 \
--ram "1024" \
--disk path=./ubuntu1604.qcow2,size=8 \
--vcpus "1" \
--os-type linux \
--os-variant ubuntu16.04 \
--network bridge=virbr0 \
--graphics none \
--console pty,target_type=serial \
--location 'http://archive.ubuntu.com/ubuntu/dists/xenial/main/installer-amd64/' \
--extra-args 'console=ttyS0,115200n8 serial'
