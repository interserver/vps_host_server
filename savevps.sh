#!/bin/bash
  if [ "\$1" = "" ]; then 
    guess="\$(top -c -b -n 1 | grep "[[:digit:]] qemu" | sed "s/^.* -name \([^ ]*\) .*\$/\1/g" | head -n 1)";
    read -e -i "\$guess" -p "VPS Name: " vps;
  else 
    vps="\$1"; 
  fi; 
  if [ "\$(echo "\$vps" |grep "^[a-z]")" = "" ]; then   
    if [ -e /win ]; then
      vps="windows\$vps";
    else
      vps="linux\$vps";
    fi;
  fi;
  virsh save \$vps /var/lib/libvirt/autosuspend/\$vps.dump;

