#!/bin/bash
suspended="$(ls /var/lib/libvirt/autosuspend/ 2>/dev/null | sed s#"\.dump$"#""#g)";
if [ "$suspended" != "" ]; then
	for vps in $suspended; do
		virsh destroy $vps; 
done; 
/etc/init.d/libvirt-suspendonreboot start
/root/cpaneldirect/run_buildebtables.sh
/root/cpaneldirect/vps_refresh_all_vnc.sh
