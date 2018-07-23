#!/bin/bash
export PATH="$PATH:/bin:/usr/bin:/usr/local/bin:/sbin:/usr/sbin:/usr/local/sbin"
vps="$1"
img="$2"
if [ $# -ne 2 ]; then
	echo "Install a custom image to a VPS"
	echo "Syntax $0 [vps] [url]"
	echo " ie $0 windows1 http://cloud-images.ubuntu.com/trusty/current/trusty-server-cloudimg-amd64-disk1.img"
	exit;
elif ! virsh dominfo $vps >/dev/null 2>&1; then
	echo "Invalid VPS $vps";
	exit
fi
destdev=/dev/vz/$vps
virsh destroy $vps
#first we get the free disk space on the KVM in G
p="$(pvdisplay -c |grep -v -e centos -e backup)"
pesize=$(($(echo "$p" | cut -d: -f8) * 1000))
totalpe="$(echo "$p" | cut -d: -f9)"
freepe="$(echo "$p" | cut -d: -f10)"
totalb=$(($pesize*$totalpe))
totalg=$((${totalb}/1000000000))
freeb=$(($pesize*$freepe))
freeg=$((${freeb}/1000000000))
#next we get the size of the image in G
echo "LVM  $freeb / $totalb Free"
imgsize=$(curl -L -s -I "$img" | grep "^Content-Length:" | sed -e s#"\n"#""#g | cut -d" " -f2 | sed s#"\r"#""#g)
imgbuff=$(echo "$imgsize + 10000000" | bc -l)
if [ "$imgsize" == "" ]; then
	echo "Error with $img"
	echo "headers are"
	cat curl_headers.txt
	exit
fi
imgbuff=$(($imgsize+10000000))
if [ $imgbuff -ge $freeb ]; then
	echo "Not Enough Free Space"
	echo "Image Size $imgsize"
	echo "Free Space $freeb ($freeg G / $totalg G)"
	exit
fi
lvsize=$(($(($(($imgbuff/512))+1))*512))
lvcreate -y -L${lvsize}B -nimage_storage vz
mke2fs /dev/vz/image_storage
mkdir -p /image_storage
mount /dev/vz/image_storage /image_storage
curl -L -o /image_storage/image.img "$img"
format="$(qemu-img info /image_storage/image.img |grep "file format:" | awk '{ print $3 }')"
if [ "$format" = "raw" ] ; then
	dd if=/image_storage/image.img of=$destdev
else
	qemu-img convert /image_storage/image.img -O raw "$destdev"
fi
umount /image_storage
rmdir /image_storage
virsh vol-delete --pool vz image_storage
virsh start $vps
bash /root/cpaneldirect/run_buildebtables.sh;
/root/cpaneldirect/vps_refresh_vnc.sh $vps
