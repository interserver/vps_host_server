#!/bin/bash
# Converts systemsto using io=native
base="$(readlink -f "$(dirname "$0")")";
cd /etc/libvirt/qemu
sleeptime=10m
delaytime=1s
if [ "$1" = "enable" ]; then
 for i in *xml; do
  a="$(echo "$i" |sed s#".xml"#""#g)";
  sed s#"<driver name='qemu' type='raw' cache='none'/>"#"<driver name='qemu' type='raw' cache='none' io='native'/>"#g -i "$i";
  virsh define "$i";
 done
 running="$(virsh list |grep -v -e "^--" -e "Id .*Name .*State" -e "^$" | awk '{ print $2 }')"
 for i in $running; do
   virsh shutdown $i;
 done
 echo "Sleeping 5minutes to let the systems shutdown cleanly"
 sleep $sleeptime
 echo "waking up and finishing the job"
 for i in $running; do
   virsh destroy $i;
   sleep $delaytime
   virsh start $i;
   bash ${base}/run_buildebtables.sh;
   ${base}/vps_refresh_vnc.sh $i
 done
 sed s#"<driver name='qemu' type='raw' cache='none'/>"#"<driver name='qemu' type='raw' cache='none' io='native'/>"#g -i ${base}/windows.xml
 sed s#"<driver name='qemu' type='raw' cache='none'/>"#"<driver name='qemu' type='raw' cache='none' io='native'/>"#g -i ${base}/linux.xml
elif [ "$1" = "disable" ]; then
 for i in *xml; do
  a="$(echo "$i" |sed s#".xml"#""#g)";
  sed s#"<driver name='qemu' type='raw' cache='none' io='native'/>"#"<driver name='qemu' type='raw' cache='none'/>"#g -i "$i";
  virsh define "$i";
 done
 running="$(virsh list |grep -v -e "^--" -e "Id .*Name .*State" -e "^$" | awk '{ print $2 }')"
 for i in $running; do
   virsh shutdown $i;
 done
 echo "Sleeping 5minutes to let the systems shutdown cleanly"
 sleep $sleeptime
 echo "waking up and finishing the job"
 for i in $running; do
   virsh destroy $i;
   sleep $delaytime
   virsh start $i;
   bash ${base}/run_buildebtables.sh;
   ${base}/vps_refresh_vnc.sh $i
 done
 sed s#"<driver name='qemu' type='raw' cache='none' io='native'/>"#"<driver name='qemu' type='raw' cache='none'/>"#g -i ${base}/windows.xml
 sed s#"<driver name='qemu' type='raw' cache='none' io='native'/>"#"<driver name='qemu' type='raw' cache='none'/>"#g -i ${base}/linux.xml
else
	echo "Must call with an argument"
	echo
	echo "Correct Syntax:"
	echo " $0 <enable|disable>"
	echo
	echo "Options"
	echo " enable - Turns on Native IO"
	echo " disable - Turns off Native IO"
fi


