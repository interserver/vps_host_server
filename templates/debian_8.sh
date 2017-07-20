#!/bin/bash
virt-install \
--name debian8 \
--ram "1024" \
--disk path=./debian8.qcow2,size=8 \
--vcpus "1" \
--os-type linux \
--os-variant generic \
--network bridge=virbr0 \
--graphics none \
--console pty,target_type=serial \
--location 'http://ftp.nl.debian.org/debian/dists/jessie/main/installer-amd64/' \
--extra-args 'console=ttyS0,115200n8 serial'
