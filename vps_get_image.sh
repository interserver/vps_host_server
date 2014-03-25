#!/bin/bash
export PATH="$PATH:/bin:/usr/bin:/usr/local/bin:/sbin:/usr/sbin:/usr/local/sbin"
img="$1"
if [ $# -ne 1 ]; then
	echo "Downloads a custom image to a temp LVM directory"
	echo ""
	echo "Syntax $0 [url]"
	echo " ie $0 http://cloud-images.ubuntu.com/trusty/current/trusty-server-cloudimg-amd64-disk1.img"
	echo ""
	echo "Warning - Leaves /image_storage mounted and creatd as an LVM"
	exit;
fi
#first we get the free disk space on the KVM in G
p="$(pvdisplay -c)"
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
#imgbuff=$(echo "(4 * $imgsize)" | bc -l)
imgbuff=$(($imgsize*4))
if [ "$imgsize" == "" ]; then
	echo "Error with $img"
	echo "headers are"
	cat curl_headers.txt
	exit
fi
if [ $imgbuff -ge $freeb ]; then
	echo "Not Enough Free Space"
	echo "Image Size $imgsize"
	echo "Free Space $freeb ($freeg G / $totalg G)"
	exit
fi
lvsize=$(($(($(($imgbuff/512))+1))*512))
lvcreate -L${lvsize}B -nimage_storage vz
mke2fs /dev/vz/image_storage
mkdir -p /image_storage
mount /dev/vz/image_storage /image_storage
curl -L -o /image_storage/image.img "$img"
format="$(qemu-img info /image_storage/image.img |grep "file format:" | awk '{ print $3 }')"
if [ "$format" ! "raw" ] ; then
	qemu-img convert /image_storage/image.img -O raw /image_storage/image.raw.img
	rm -f /image_storage/image.img
else
	mv -f /image_storage/image.img /image_storage/image.raw.img
fi
rm -f curl_headers.txt
