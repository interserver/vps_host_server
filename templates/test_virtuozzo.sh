#!/bin/bash
templates="centos-6-x86_64
centos-7-x86_64
debian-10.0-x86_64
debian-7.0-x86_64
debian-8.0-x86_64
debian-9.0-x86_64
fedora-23-x86_64
sles-11-x86_64
sles-12-x86_64
sles-15-x86_64
suse-42.1-x86_64
suse-42.2-x86_64
suse-42.3-x86_64
ubuntu-14.04-x86_64
ubuntu-16.04-x86_64
ubuntu-17.10-x86_64
ubuntu-18.04-x86_64
vzlinux-6-x86_64
vzlinux-7-x86_64"
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
    ~/cpaneldirect/templates/install_virtuozzo.sh $i "$1";
done
