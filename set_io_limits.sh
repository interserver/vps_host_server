#!/bin/bash
IFS="
"
eval declare -A adminvps=($(for i in $(curl -s https://mynew.interserver.net/adminvps.php); do echo -n "[$i]=1 ";done))
eval declare -A runningvps=($(for i in $(virsh list --name); do echo "[$i]=1 ";done))
for line in $(cat /root/cpaneldirect/vps.slicemap); do
  vps=$(echo $line|cut -d: -f1)
  slices=$(echo $line|cut -d: -f2)
  iops=$((100 + $((100 * $slices))))
  io=$((100000000 * $slices))
  echo -n "vps $vps: slices $slices = iops ${iops} + io ${io}, "
  if [ "${adminvps[$vps]}" = "1" ]; then
    echo "skipping interserver/admin service"
  else
    for dev in $(virsh domblklist $vps|grep "^ .* */"|cut -d" " -f2); do
      echo -n "updating $dev config, "
      virsh blkdeviotune $vps $dev --total-iops-sec ${iops} --total-bytes-sec ${io} --config |grep -v "^$"
      if [ -n ${runningvps[$vps]} ]; then
        echo "updating $dev live"
        virsh blkdeviotune $vps $dev --total-iops-sec ${iops} --total-bytes-sec ${io} --live |grep -v "^$"
      fi
    done
  fi
done
