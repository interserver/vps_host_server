#!/bin/bash
t=/var/lib/libvirt/images/guest.qcow2;
max=300
virsh destroy guest 2>/dev/null;
end=0
#for i in /var/www/html/qcow2/linux/*.qcow2; do
for i in *.qcow2; do
#for i in /var/www/html/qcow2/linux/opensuse-13.1.qcow2 /var/www/html/qcow2/linux/opensuse-13.2.qcow2 /var/www/html/qcow2/linux/opensuse-42.1.qcow2 /var/www/html/qcow2/linux/opensuse-tumbleweed.qcow2 /var/www/html/qcow2/linux/scientificlinux-6.qcow2 /var/www/html/qcow2/linux/ubuntu-10.04.qcow2 /var/www/html/qcow2/linux/ubuntu-12.04.qcow2 /var/www/html/qcow2/linux/ubuntu-14.04.qcow2 /var/www/html/qcow2/linux/ubuntu-16.04.qcow2 /var/www/html/qcow2/linux/ubuntu-18.04.qcow2 /var/www/html/qcow2/linux/ubuntudesktop.qcow2; do
	echo "Copying Template ${i}"
	cat ${i} | pv > ${t} || end=1;
	if [ ${end} -eq 1 ]; then
		break;
	fi;
	#guestmount -v -i -w -a ${t} /mnt;
	#guestunmount -v /mnt;
	start=$(cat /var/log/syslog|wc -l)
	ip=""
	s=$(date +%s)
	echo "Starting Guest"
	tail -n 0 -f /var/log/syslog &
	p=$!
	virsh start guest
	echo "Waiting for DHCP"
	while [ "${ip}" = "" ] && [ $(($(date +%s) - ${s})) -lt ${max} ]; do
		ip=$(tail -n $(($(cat /var/log/syslog|wc -l) - ${start})) /var/log/syslog|grep DHCPACK|cut -d" " -f7)
		sleep 2s || end=1;
	done
	kill -9 ${p}
	if [ $(($(date +%s) - ${s})) -ge ${max} ]; then
		echo "Failed DHCP after ${max} Seconds"
		echo "${i} dhcp" >> /var/www/html/bad.txt
	else
		echo "Got IP ${ip} in $(($(date +%s) - ${s})) seconds"
		echo "Waiting on SSH to come up"
		s=0
		while ! nmap ${ip} -p 22 -sT --host-timeout 10|grep "22/tcp *open"; do
			sleep 2s
			s=$((${s} + 2));
			if [ ${s} -ge ${max} ]; then
				break;
			fi;
		done
		sleep 30s;
		echo "Testing SSH Login"
		ssh-keygen -f ~/.ssh/known_hosts -R ${ip}
		if ~/test_ssh.expect ${ip} root interserver123; then
			echo "Good Login"
			echo "${i}" >> /var/www/html/good.txt
		else
			echo "Failed Login"
			echo "${i} ssh" >> /var/www/html/bad.txt
		fi
	fi
	virsh destroy guest 2>/dev/null;
	if [ ${end} -eq 1 ]; then
		break;
	fi;
done
echo "Good Templates ($(cat /var/www/html/good.txt|wc -l)):"
cat /var/www/html/good.txt
echo "Bad Templates ($(cat /var/www/html/bad.txt|wc -l)):"
cat /var/www/html/bad.txt
