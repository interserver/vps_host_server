#!/bin/bash
virt-install \
--name ubuntu1004 \
--ram 1024 \
--disk path=./ubuntu1004.qcow2,size=8 \
--vcpus 1 \
--os-type linux \
--os-variant ubuntu10.04 \
--network bridge=virbr0 \
--graphics none \
--console pty,target_type=serial \
--location 'http://archive.ubuntu.com/ubuntu/dists/lucid/main/installer-amd64/' \
--extra-args 'console=ttyS0,115200n8 serial'
