#!/bin/bash
OIFS="$IFS";
IFS="\n"; 
for devs in $(pvdisplay -C --separator , --noheadings --nosuffix | cut -d, -f1-3 | sed s#" "#""#g | tr , " "); do 
 name="$(echo "$devs" | cut -d" " -f2)";
 dev="$(echo "$devs" | cut -d" " -f1)";
 type="$(echo "$devs" | cut -d" " -f3)";
 echo "Dev $dev Name $name Type $type";
 if [ "$(virsh -q pool-list --all --type logical |  grep "^ ${name} ")" = "" ]; then
  virsh pool-define-as $name logical --source-dev $dev --source-name $name --source-format $type;
  virsh pool-autostart $name;
  virsh pool-start $name;
 fi;
done;
IFS="$OIFS";
