#!/bin/bash
# converting right now with:
# qemu-img -p convert xenvps-vm380_img xenvps-vm380_swap -O raw xenvps-vm380.img
#S=$1
#ID=$2
#S=/home/testing/vm111_backup.img
S="/home/testing/xenvps-vm380_img /home/testing/xenvps-vm380_swap"
ID=vm380
#determine if we are getting a disk image or partition image(s)
# each image takes up the image size in bites + 512 bytes per partition
# the extra 512 bytes (1 sector) is already in the image file
# first partition will start on sector 63, so need to pad with 63*512=32256 for image
kpartx -dv /dev/vz/$ID
virsh vol-delete --pool vz $ID
if [ "$(file -s $S|grep "boot sector")" = "" ]; then
  echo "Detected Partition Image(s) Passed - First Converting To Disk Image"
  #we need to convert these partition(s) to a disk image
  #get the number of parttions and the size of them
  partitions=$(echo $S |wc -w)
  if [ $partitions -gt 4 ]; then
   echo "Cannot currently convert more than 4 partitions, ask for this feature"
   exit
  fi
  #size=$(($(($(($(du -bc $S | tail -n 1 | awk '{ print $1 }') / 512)) + 1)) * 512))
  #size="$(du -bc $S | tail -n 1 | awk '{ print $1 }')"
  size=$(($(($partitions * 512)) + 32256 + $(du -bc $S | tail -n 1 | awk '{ print $1 }')))
  lvcreate -y -L ${size}B -n $ID vz
diskbytes=$(fdisk -l /dev/vz/$ID  |grep "^Disk.* bytes$" | cut -d: -f2-  | awk '{ print $3 }')
diskcylinders=$(fdisk -l /dev/vz/$ID  |grep "cylinders$" | cut -d, -f3-  | awk '{ print $1 }')
cylinderbytes=$(fdisk -l /dev/vz/$ID  |grep "^Units = cylinder.*bytes$" | cut -d= -f3-  | awk '{ print $1 }')
  curpart=1
  curcyl=1
  #cursect=63
  #cursect=1
  fdiskcmd=""
  ddcmd=""
  for image in $S; do
    partsize=$(($(du -b $image | awk '{ print $1 }') - 512))
    partcyl=$(($partsize / $cylinderbytes))
    if [ $(($partsize % $cylinderbytes)) != 0 ]; then
      partcyl=$(($partcyl + 1))
    fi
    #partsize=$(du -b $image | awk '{ print $1 }')
    partsect=$(($partsize / 512))
    partfile="$(file $image | cut -d" " -f2-)"
    if [ "$(echo "$partfile" | grep "swap")" != "" ]; then
      parttype=82
    elif [ "$(echo "$partfile" |grep "Linux")" != "" ]; then
      parttype=83
    else
      echo "Unsure about $image Parititon Type, ask me to add this type"
      exit
    fi
    echo "Partion Image $image  Size(b) $partsize   Sectors $partsect    Type $parttype"
    fdiskcmd="${fdiskcmd}n\np\n${curpart}\n${curcyl}\n+${partcyl}\n"
    #fdiskcmd="${fdiskcmd}n\np\n${curpart}\n\n+${partsize}\n"
    if [ $curpart == 1 ]; then
      fdiskcmd="${fdiskcmd}a\n1\nt\n${parttype}\np\n"
    else
      fdiskcmd="${fdiskcmd}t\n${curpart}\n${parttype}\np\n"
    fi
    ddcmd="${ddcmd}echo \"Writing $image to ${ID}p${curpart}\";dd if=$image of=/dev/mapper/vz-${ID}p${curpart}\n"
    #update counters for possible next pass
    curcyl=$(($curcyl + $partcyl + 1))
    #cursect=$(($cursect + $partsect + 1))
    curpart=$(($curpart + 1))	
  done
  echo -e "FdiskCMD:$fdiskcmd"
  #echo -e "${fdiskcmd}w\n" | fdisk -u /dev/vz/${ID}
  echo -e "${fdiskcmd}w\n" | fdisk /dev/vz/${ID}
  #sh $fdiskcmd
else
  echo "Detected Disk image already, no conversion needed"
  size="$(du -bc $S | tail -n 1 | awk '{ print $1 }')"
  lvcreate -y -L ${size}B -n $ID vz
  ddcmd="dd if=$S of=/dev/vz/${ID}\n"
fi
kpartx -av /dev/vz/${ID}
#echo -e "DDCMD:$ddcmd"
echo -e "$ddcmd" | sh
mount /dev/mapper/vz-${ID}p1 /mnt
if [ -e /mnt/boot/grub ]; then
  GRUBDIR=/mnt/boot/grub
elif [ -e /mnt/grub ]; then
  GRUBDIR=/mnt/grub
else
  echo "Not sure how to handle no grub directory on the first partition, ask me to fix this"
  exit
fi
if [ ! -e $GRUBDIR/device.map ]; then
  echo "(hd0)	/dev/vda" > $GRUBDIR/device.map
else
  sed s#"xvda"#"vda"#g -i $GRUBDIR/device.map
fi
if [ -e $GRUBDIR/grub.conf ]; then
  sed s#"xvda"#"vda"#g -i $GRUBDIR/grub.conf
  sed s#"console=hvc0"#""#g -i $GRUBDIR/grub.conf
  sed s#"xencons=tty0"#""#g -i $GRUBDIR/grub.conf
fi
# there was a console= line in the menu.lst i removed 
# but i didn't see it the 2nd time around
umount /mnt
mkdir /dev/VolGroup
lvchange -f -ay /dev/VolGroup/lv_root
mount /dev/VolGroup/lv_root /mnt

umount /mnt
lvchange -f -an /dev/VolGroup/lv_root
kpartx -dv /dev/vz/$ID
