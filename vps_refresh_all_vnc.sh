#!/bin/bash
running="$(virsh list |grep -v -e "^--" -e "Id .*Name .*State" -e "^$" | awk '{ print $2 }')"
for i in "$running"; do
	/root/cpaneldirect/vps_refresh_vnc.sh "$i"
done
