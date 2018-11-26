#!/bin/bash
# VMWare MACs
#  00:05:56
#  00:50:56
#  00:0C:29
#  00:1C:14
# Xen MACs
#  00:16:3E
if [ $# -eq 0 ]; then
    echo "Syntax: ${0} <id> [module]
    id - the Service ID number
    module - vps or quickservers, defaults to vps, optional module this service is under"
    exit
fi
id=$1
if [ $# -eq 2 ]; then
    if [ "$2" = "qs" ] || [ "$2" = "quickservers" ]; then
        prefix="00:0C:29"
    else
        prefix="00:16:3E"
    fi
else
    prefix="00:16:3E"
fi
s="$(printf "%06s" $(echo "obase=16; $id"|bc)|sed s#" "#"0"#g)"
mac="${prefix}:${s:0:2}:${s:2:2}:${s:4:2}"
echo $mac;
