#!/bin/bash
vps=238639
myip=70.44.33.193
root="$2"
ip=104.37.189.227
template="$1"
mac="00:16:3e:03:84:d9"

export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/usr/sbin:/sbin:/bin:/usr/bin";
prlctl stop ${vps};
prlctl delete ${vps};
function iprogress() {
  curl --connect-timeout 60 --max-time 240 -k -d action=install_progress -d progress=$1 -d server=${vps} 'https://myvps2.interserver.net/vps_queue.php' < /dev/null > /dev/null 2>&1;
}
iprogress 10
prlctl create ${vps} --vmtype ct --ostemplate ${template};
iprogress 60
prlctl set ${vps} --userpasswd root:"${root}";
prlctl set ${vps} --swappages 1G --memsize 2048M;
prlctl set ${vps} --hostname 'webuzotest.is.cc';
prlctl set ${vps} --device-add net --type routed --ipadd ${ip} --nameserver 8.8.8.8;
iprogress 70
prlctl set ${vps} --cpus 1;
prlctl set ${vps} --cpuunits 2250;
prlctl set ${vps} --device-set hdd0 --size 30720;
iprogress 80
prlctl set ${vps} --onboot yes ;
ports=" $(prlctl list -a -i |grep "Remote display:.*port=" |sed s#"^.*port=\([0-9]*\) .*$"#"\1"#g) ";
start=5901;
found=0;
while [ $found -eq 0 ]; do
  if [ "$(echo "$ports" | grep "$start")" = "" ]; then
        found=$start;
  else
        start=$(($start + 1));
  fi;
done;
prlctl set ${vps} --vnc-mode manual --vnc-port $start --vnc-nopasswd --vnc-address 127.0.0.1;
iprogress 90
prlctl start ${vps};
iprogress 91
if [ "${template}" = "centos-7-x86_64-breadbasket" ]; then
    prlctl exec ${vps} 'yum -y remove httpd sendmail xinetd firewalld samba samba-libs samba-common-tools samba-client samba-common samba-client-libs samba-common-libs rpcbind; userdel apache'
    iprogress 92
    prlctl exec ${vps} 'yum -y install nano net-tools'
    iprogress 93
    prlctl exec ${vps} 'rsync -a rsync://rsync.is.cc/admin /admin;/admin/yumcron;echo "/usr/local/emps/bin/php /usr/local/webuzo/cron.php" > /etc/cron.daily/wu.sh && chmod +x /etc/cron.daily/wu.sh'
    iprogress 94
    prlctl exec ${vps} 'wget -N http://files.webuzo.com/install.sh -O install.sh'
    iprogress 95
    prlctl exec ${vps} 'chmod +x install.sh;./install.sh;rm -f install.sh'
    iprogress 99
    echo "Sleeping for a minute to workaround an ish"
    sleep 1m;
    echo "That was a pleasant nap.. back to the grind..."
fi;
iprogress 100

found=0
c=0
cMax=20;
while [ $found -eq 0 ] && [ $c -le ${cMax} ]; do
	echo "[${c}/${cMax}] "
        ping ${ip} -c 1 && found=1
        c=$(($c + 1))
done
echo "$template Found $found after $c" | tee -a test.log
if [ $found -eq 1 ]; then
    ssh-keygen -f ~/.ssh/known_hosts -R ${ip}
    sleep 10s
    if /root/cpaneldirect/templates/test_ssh.expect ${ip} root "${root}"; then
        echo "$template Good Login" | tee -a test.log
    else
        echo "$template Failed Login" | tee -a test.log
    fi
fi

