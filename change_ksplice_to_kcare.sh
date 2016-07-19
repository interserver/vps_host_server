#!/bin/bash
{
if [ "$(which uptrack-uname)" != "" ]; then
 uptrack-remove --all -y; 
 ln -s  ../init.d/uptrack-prefetch /etc/rc0.d/K01uptrack-prefetch; 
 ln -s  ../init.d/uptrack-prefetch /etc/rc6.d/K01uptrack-prefetch; 
 ln -s  ../init.d/uptrack-prefetch /etc/rcS.d/K12uptrack-prefetch; 
 cat /etc/init.d/uptrack-late | sed s#"uptrack-late"#"uptrack-prefetch"#g > /etc/init.d/uptrack-prefetch; 
 update-rc.d uptrack-prefetch defaults; 
 apt-get remove -y uptrack; 
 apt-get purge -y uptrack; 
 rm -f /etc/*.d/*uptrack-prefetch;
fi;
if [ "$(grep 69.175.106.203 /etc/hosts.allow)" = "" ]; then
 echo -e "ALL: 184.154.187.244\nALL: 69.175.106.203" >> /etc/hosts.allow;
fi; 
if [ -e /etc/csf ]; then
 csf -a 184.154.187.244;
 csf -a 69.175.106.203; 
fi; 
wget https://downloads.kernelcare.com/kernelcare-latest.deb -O /root/kernelcare-latest.deb  ; 
dpkg -i /root/kernelcare-latest.deb  ; 
/usr/bin/kcarectl --info  ; 
kcarectl --update ; 
kcarectl --uname ;
}

