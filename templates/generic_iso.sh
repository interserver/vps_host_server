#!/bin/bash
wget http://ftp.freebsd.org/pub/FreeBSD/releases/ISO-IMAGES/10.1/FreeBSD-10.1-RELEASE-amd64-dvd1.iso
virt-install \
--name freebsd10 \
--ram "1024" \
--disk path=./freebsd10.qcow2,size=8 \
--vcpus "1" \
--os-type generic \
--os-variant generic \
--network bridge=virbr0 \
--graphics vnc,port=5999 \
--console pty,target_type=serial \
--cdrom ./FreeBSD-10.1-RELEASE-amd64-dvd1.iso \
