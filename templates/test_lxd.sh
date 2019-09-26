#!/bin/bash
templates="ubuntu/14.04/amd64
ubuntu/16.04/amd64
ubuntu/18.04/amd64
ubuntu/18.10/amd64
oracle/6/amd64
oracle/7/amd64
opensuse/15.0/amd64
opensuse/42.3/amd64
fedora/26/amd64
fedora/27/amd64
fedora/28/amd64
debian/10/amd64
debian/8/amd64
debian/sid/amd64
debian/9/amd64
debian/7/amd64
centos/6/amd64
centos/7/amd64"
IFS="
"
if [ "$1" = "" ]; then
	echo "Missing Password Parameter"
	exit;
fi
for i in $templates; do
    echo -e "\n\t\t\t---------------------------------"
    echo -e "\t\t\t           $i"
    echo -e "\t\t\t-----------------------------------"
    ~/cpaneldirect/templates/install_lxd.sh $i "$1";
done
