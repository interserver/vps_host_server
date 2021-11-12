#!/bin/bash
export base="$(readlink -f "$(dirname "$0")")";
if [ $# -ne 1 ]; then
	echo "Refresh VNC settings for VPS"
	echo "Syntax $0 [vps]"
	echo " ie $0 windows1"
else
	vps="$1"
	id="$(echo "$vps" | sed s#"[a-zA-Z]"#""#g)"
	vnc="$(grep "^$id:" ${base}/vps.vncmap | cut -d: -f2)"
	if [ "$vnc" != "" ]; then
		${base}/cli/provirted.phar vnc setup $vps $vnc
	fi
fi

