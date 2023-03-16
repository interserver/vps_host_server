#!/bin/bash
IFS="
"
eval declare -A adminvps=($(for i in $(curl -s https://mynew.interserver.net/adminvps.php); do echo -n "[$i]=1 ";done))
eval declare -A runningvps=($(for i in $(virsh list --name); do echo "[$i]=1 ";done))
for i in $(cat /root/cpaneldirect/vps.slicemap); do
  v=$(echo $i|cut -d: -f1)
  s=$(echo $i|cut -d: -f2)
  iops=$((100 + $((100 * $s))))
  io=$((100000000 * $s))
  echo -n "vps $v: slices $s = iops ${iops} + io ${io}, "
  if [ "${adminvps[$v]}" = "1" ]; then
    echo "skipping interserver/admin service"
  else
    echo -n "updating config, "
    virsh blkdeviotune $v vda --total-iops-sec ${iops} --total-bytes-sec ${io} --config |grep -v "^$"
    if [ -n ${runningvps[$v]} ]; then
      echo "updating live"
      virsh blkdeviotune $v vda --total-iops-sec ${iops} --total-bytes-sec ${io} --live |grep -v "^$"
    fi
  fi
done
