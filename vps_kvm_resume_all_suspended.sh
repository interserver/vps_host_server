#!/bin/bash
export base="$(readlink -f "$(dirname "$0")")";
suspended="$(ls /var/lib/libvirt/autosuspend/ 2>/dev/null | sed s#"\.dump$"#""#g)";
if [ "$suspended" != "" ]; then
	for vps in $suspended; do
		virsh destroy $vps;
	done;
	/etc/init.d/libvirt-suspendonreboot start;
fi;
${base}/run_buildebtables.sh;
${base}/vps_refresh_all_vnc.sh;
