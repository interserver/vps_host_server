#!/bin/bash
virt-install \
--name ubuntu1404 \
--ram "1024" \
--disk path=./ubuntu1404.qcow2,size=8 \
--vcpus "1" \
--os-type linux \
--os-variant ubuntu14.04 \
--network bridge=virbr0 \
--graphics none \
--console pty,target_type=serial \
--location 'http://archive.ubuntu.com/ubuntu/dists/trusty/main/installer-amd64/' \
--extra-args 'console=ttyS0,115200n8 serial'
