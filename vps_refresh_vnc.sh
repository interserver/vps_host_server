#!/bin/bash
if [ $# -ne 1 ]; then
	echo "Refresh VNC settings for VPS"
	echo "Syntax $0 [vps]"
	echo " ie $0 windows1"
else
	vps="$1"
	id="$(echo "$vps" | sed s#"[a-zA-Z]"#""#g)"
	vnc="$(grep "^$id:" /root/cpaneldirect/vps.vncmap | cut -d: -f2)"
	if [ "$vnc" != "" ]; then
		/root/cpaneldirect/vps_kvm_setup_vnc.sh $vps $vnc
	fi
fi

