#!/bin/bash
export base="$(readlink -f "$(dirname "$0")")";
running="$(virsh list |grep -v -e "^--" -e "Id .*Name .*State" -e "^$" | awk '{ print $2 }')"
for i in $running; do
	${base}/vps_refresh_vnc.sh $i
done
