#!/bin/bash
export PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin";
export base="$(readlink -f "$(dirname "$0")")";
set -x;
name=$1;
if [ $# -ne 1 ]; then
 echo -e "Clear VPS Password\nSyntax $0 [name]\n ie $0 windows1337";
elif ! virsh dominfo ${name} >/dev/null 2>&1; then
 echo "VPS ${name} doesn't exists!";
else
 ${base}/provirted.phar stop --virt=kvm {$name}
 mkdir -p /mntpass
 guestmount -d {$name} -i -w /mntpass
 ${base}/enable_user_and_clear_password -u Administrator /mntpass/Windows/System32/config/SAM;
 guestunmount /mntpass
 rmdir /mntpass
 ${base}/provirted.phar start --virt=kvm {$name}
fi
