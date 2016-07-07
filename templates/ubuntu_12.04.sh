#!/bin/bash
virt-install \
--name ubuntu1204 \
--ram 1024 \
--disk path=./ubuntu1204.qcow2,size=8 \
--vcpus 1 \
--os-type linux \
--os-variant ubuntu12.04 \
--network bridge=virbr0 \
--graphics none \
--console pty,target_type=serial \
--location 'http://archive.ubuntu.com/ubuntu/dists/precise/main/installer-amd64/' \
--extra-args 'console=ttyS0,115200n8 serial'
