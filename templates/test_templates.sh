#!/bin/bash
if [ ! -e ~/.provirtedc/test.env ]; then
	echo "No ~/.provirtedc/test.env file exists, creating..
echo "client_ip=1.2.3.4
vps_vzid=vps100
vps_hostname=vps.server.com
vps_ip=6.7.8.9
vps_hd=50
vps_ram=2048
vps_cpu=2
vps_password=password
" > ~/.provirtedc/test.env
	echo "file created, please edit it to suit your needs"
fi
source ~/.provirted/test.env
timeoutMin=5
for i in $*; do
	i="$(basename "$i" .qcow2)";
	provirted stop -f ${vps_vzid};
	provirted destroy ${vps_vzid};
	zfs destroy vz/${vps_vzid};
	provirted create -c ${client_ip} ${vps_vzid} ${vps_hostname} ${vps_ip} ${i} ${vps_hd} ${vps_ram} ${vps_cpu} ${vps_password} || {
		echo "$i" >> errrors.txt
		continue;
	}
	date
	read -t $((${timeoutMin} * 60)) -p "press enter to skip 3m sleep and continue" -N 1 read
	provirted test ${vps_vzid} ${vps_password} && echo "$i" >> all_good.txt || echo "$i" >> errors.txt
done

