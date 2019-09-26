#!/bin/bash
IFS="
"
set -x
if [ "$1" = "" ]; then
	echo "Missing Password Parameter"
	exit;
fi
for i in /vz/build/*.qcow2; do
    i="$(echo "$i"|sed s#"\.qcow2"#""#g)";
    echo -e "\n\t\t\t---------------------------------"
    echo -e "\t\t\t           $i"
    echo -e "\t\t\t-----------------------------------"
    ~/cpaneldirect/templates/install_kvm.sh $i "$1";
done
set +x
