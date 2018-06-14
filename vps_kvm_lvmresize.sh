#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"
if [ "$(kpartx 2>&1 |grep sync)" = "" ]; then
  kpartxopts=""
else
  kpartxopts="-s"
fi
name=$1
size=$2
IFS="
"
if [ -e /etc/redhat-release ] && [ $(cat /etc/redhat-release| sed s#"Linux "#""#gi | cut -d" " -f3 | cut -d"." -f1) -le 6 ]; then
  if [ $(echo "$(e2fsck -V 2>&1 |head -n 1 | cut -d" " -f2 | cut -d"." -f1-2) * 100" | bc | cut -d"." -f1) -le 141 ]; then
	if [ ! -e /opt/e2fsprogs/sbin/e2fsck ]; then
	  pushd $PWD;
	  cd /admin/ports
	  ./install e2fsprogs
	  popd;
	fi;
	export PATH="/opt/e2fsprogs/sbin:$PATH";
  fi;
fi;
if [ $# -ne 2 ]; then
 echo "Create a New LVM (non destructivly)"
 echo "Syntax $0 [name] [size]"
 echo " ie $0 windows1337 101000"
#check if vps exists
else
 echo " + Resizing LVM ${name} To ${size}"
 oldsize="$(lvdisplay /dev/vz/$name --units m|grep "LV Size"| awk '{ print $3 }'| cut -d\. -f1)"
 if [ $oldsize -gt $size ]; then
  echo " + Skipping Resize since the New size $size is smaller than the original size $oldsize"
 else
  if [ "$(lvdisplay /dev/vz/$name | grep "LV Size *"$(echo "$size / 1024" | bc -l | cut -d\. -f1))" = "" ]; then
   lvresize -f -L${size} /dev/vz/${name}
   sects="$(fdisk -l -u /dev/vz/${name}  | grep -e "total .* sectors$" | sed s#".*total \(.*\) sectors$"#"\1"#g)"
   t="$(fdisk -l -u /dev/vz/${name} | sed s#"\*"#""#g | grep "^/dev/vz" | tail -n 1)"
   p="$(echo $t | awk '{ print $1 }')"
   fs="$(echo $t | awk '{ print $5 }')"
   pn="$(echo "$p" | sed s#"/dev/vz/${name}p"#""#g)"
   start="$(echo $t | awk '{ print $2 }')"
   if [ "$fs" = "83" ]; then
  echo " + Resizing Last Linux Partition To Use All Free Space"
  echo -e "d\n$pn\nn\np\n$pn\n$start\n\n\n\nw\nprint\nq\n" | fdisk -u /dev/vz/${name}
  kpartx $kpartxopts -av /dev/vz/${name}
  if [ -e "/dev/mapper/vz-${name}p${pn}" ]; then
   pname="vz-${name}"
  else
   pname="$name"
  fi
  fsck -T -p -f /dev/mapper/${pname}p${pn}
  if [ -f "$(which resize4fs 2>/dev/null)" ]; then
   resizefs="resize4fs"
  else
   resizefs="resize2fs"
  fi
  $resizefs /dev/mapper/${pname}p${pn}
  kpartx $kpartxopts -d /dev/vz/${name}
   fi
  else
   echo "I think im already at the desired size"
  fi
 fi
fi
