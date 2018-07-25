#!/bin/bash
function vaddpool() {
  if [ "$(virsh pool-list --name|grep -v "^$"|grep "$2")" = "" ]; then 
    echo "Adding $1 Pool $2";
    if [ "$2" = "logical" ]; then
      virsh pool-define-as "$2" $1 --source-name "$2" --target /dev/$2
    else
      virsh pool-define-as "$2" $1 --source-name "$2"
    fi
    virsh pool-start "$2";
    virsh pool-autostart "$2";
  else 
    echo "Skipping already added $1 Pool $2";
  fi;
}
if [ "$(which virsh 2>/dev/null)" != "" ]; then
  for i in $(zpool list -H -p 2>/dev/null|cut -d"$(echo -e "\t")" -f1); do
    vaddpool zfs "$i";
  done;
  for i in $(pvdisplay -c 2>/dev/null|cut -d: -f2); do
    vaddpool logical "$i";
  done;
fi;
