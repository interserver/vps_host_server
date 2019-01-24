#!/bin/bash
if [ ! -d /root/cpaneldirect ]; then
  exit;
fi;
/admin/upscripts;
/root/cpaneldirect/upscripts || rsync --timeout=20000 -a rsync://vpsadmin.interserver.net/vps/cpaneldirect/ /root/cpaneldirect/;
if [ ! -e /cloud ]; then
  /scripts/upscripts || rsync --timeout=20000 -a rsync://vpsadmin.interserver.net/vps/kvm/ /scripts/;
fi;
if [ ! -d /vz/templates ]; then
  if [ -e /sbin/zfs ]; then
    zfs create vz/templates;
    zfs set quota=100G vz/templates;
  else
    if [ ! -e /dev/vz/templates ]; then
      if [ "$(lvdisplay  |grep "Allocated pool")" = "" ]; then
        lvcreate -y -L60G -ntemplates vz;
      else
        lvcreate -y -V60G -T vz/thin -ntemplates;
      fi;
      mke2fs /dev/vz/templates;
      tune2fs -j /dev/vz/templates;
    fi;
    if [ "$(grep -v vz/templates /etc/fstab)" = "" ]; then
      mkdir -p /vz/templates;
      echo "/dev/vz/templates     /vz/templates ext4 defaults 0 0" >> /etc/fstab;
    fi;
    mount -a;
  fi
fi
if [ -e /sbin/zfs ]; then
  type=qcow2;
else
  type=raw;
fi;
if [ -e /win ]; then
  os=windows;
elif [ -e /lin ]; then
  os=linux;
else
  os=all;
fi;
rsync --delete -apP --inplace rsync://kvmtemplates.is.cc/${type}/${os}/ /vz/templates/;
