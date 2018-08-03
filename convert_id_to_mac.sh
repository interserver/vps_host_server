#!/bin/bash
id=$*
s="$(printf "%06s" $(echo "obase=16; $id"|bc)|sed s#" "#"0"#g)"
mac="00:16:3E:${s:0:2}:${s:2:2}:${s:4:2}"
echo $mac;
