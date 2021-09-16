#!/bin/bash
export PATH="$PATH:/bin:/usr/bin:/usr/local/bin:/sbin:/usr/sbin:/usr/local/sbin"
img="$1"
showhelp=0
outformat="uncompressed"
uselvm=1
usezfs=0
if [ $# -gt 1 ]; then
    idx=1;
	while [ $idx -lt $# ]; do
		idx=$(($idx + 1))
		arg="$(eval echo '$'$idx)"
		echo "Arg: $arg"
		if [ "$arg" = "lvm" ]; then
			uselvm=1
			usezfs=0
		elif [ "$arg" = "nolvm" ]; then
			uselvm=0
			usezfs=0
		elif [ "$arg" = "zfs" ]; then
			uselvm=0
			usezfs=1
		elif [ "$arg" = "gzip" ]; then
			outformat="$arg"
		elif [ "$arg" = "uncompressed" ]; then
			outformat="$arg"
		else
			echo "Invalid Syntax $*"
			showhelp=1;
		fi
	done
fi
if [ $# -lt 1 ] || [ $# -gt 3 ]; then
	showhelp=1;
fi
if [ $showhelp -eq 1 ]; then
	echo "Downloads a custom image to a temp LVM directory"
	echo ""
	echo "Syntax $0 <url> [outformat] [lvm|nolvm|zfs]"
	echo "  [outformat] optional, specifies output format, can be 'uncompressed', 'gzip'"
	echo "    defaults to uncompressed"
	echo "  [lvm] optional, enables creation of an LVM partiion and storing on the LVM partition"
	echo "    enabled by default"
	echo "  [nolvm] optional, disable creation of an LVM partiion, instead stores in /"
	echo "  [zfs] optional, stores in /vz/templates"
	echo " ie $0 http://cloud-images.ubuntu.com/trusty/current/trusty-server-cloudimg-amd64-disk1.img"
	echo "  or"
	echo " ie $0 http://cloud-images.ubuntu.com/trusty/current/trusty-server-cloudimg-amd64-disk1.img [gzip]"
	echo ""
	echo "Warning - Leaves /image_storage mounted and creatd as an LVM"
	exit;
fi
#first we get the free disk space on the KVM in G
if [ $uselvm -eq 1 ]; then
	p="$(pvdisplay -c |grep :vz:)"
	pesize=$(($(echo "$p" | cut -d: -f8) * 1000))
	totalpe="$(echo "$p" | cut -d: -f9)"
	freepe="$(echo "$p" | cut -d: -f10)"
	totalb=$(($pesize*$totalpe))
	totalg=$((${totalb}/1000000000))
	freeb=$(($pesize*$freepe))
	freeg=$((${freeb}/1000000000))
	#next we get the size of the image in G
	echo "LVM  $freeb / $totalb Free"
else
	freeb=$(df -B 1 / |grep / | awk '{ print $4 }')
	freeg=$(df -B 1G / |grep / | awk '{ print $4 }')
fi
imgsize=$(curl -L -s -I "$img" | grep "^Content-Length:" | sed -e s#"\n"#""#g | cut -d" " -f2 | sed s#"\r"#""#g)
#imgbuff=$(echo "(4 * $imgsize)" | bc -l)
imgbuff=$(($imgsize*10))
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
if [ $uselvm -eq 1 ]; then
	lvsize=$(($(($(($imgbuff/512))+1))*512))
	virsh vol-create-as --pool vz --name image_storage --capacity ${lvsize}
	mke2fs -q /dev/vz/image_storage
	mkdir -p /image_storage
	mount /dev/vz/image_storage /image_storage
	image_name="/image_storage/image.img"
elif [ $usezfs -eq 1 ]; then
	image_name="/vz/templates/image.qcow2"
else
	image_name="/image.img"
fi
curl -L -o ${image_name} "$img"
if [ "$(file ${image_name}|grep ":.*bzip2")" != "" ]; then
 echo "BZIP2 Image detected, uncompressing"
 mv -f ${image_name} ${image_name}.bz2
 bunzip2 ${image_name}.bz2
elif [ "$(file ${image_name}|grep ":.*gzip")" != "" ]; then
 echo "GZIP Image detected, uncompressing"
 mv -f ${image_name} ${image_name}.gz
 if [ "$outformat" != "gzip" ]; then
  gunzip ${image_name}.gz
 fi
elif [ "$(file ${image_name}|grep ":.*XZ")" != "" ]; then
 echo "XZ Image detected, uncompressing"
 mv -f ${image_name} ${image_name}.xz
 xz -d ${image_name}.xz
fi
if [ "$outformat" != "gzip" ]; then
	format="$(qemu-img info ${image_name} |grep "file format:" | awk '{ print $3 }')"
	echo "Image Format Is $format"
	if [ $usezfs -eq 1 ] && [ "$format" != "qcow2" ]; then
		echo "Converting to qcow2"
		mv -f ${image_name} ${image_name}.img
		qemu-img convert -p ${image_name}.img -O qcow2 ${image_name}.qcow2
		rm -f ${image_name}.img
	if [ "$format" != "raw" ] ; then
		echo "Converting to Raw"
		mv -f ${image_name} ${image_name}.img
		qemu-img convert -p ${image_name}.img -O raw ${image_name}
		rm -f ${image_name}.img
	fi
fi
rm -f curl_headers.txt
