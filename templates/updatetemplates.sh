for template in `ls /root | grep qcow2`; do mv -v $template /var/www/html/$template; done
cd /var/www/html/

wget -N http://ftp.freebsd.org/pub/FreeBSD/releases/VM-IMAGES/10.4-RELEASE/amd64/Latest/FreeBSD-10.4-RELEASE-amd64.qcow2.xz && /bin/rm FreeBSD-10.4-RELEASE-amd64.qcow2 && unxz -k FreeBSD-10.4-RELEASE-amd64.qcow2.xz
wget -N http://ftp.freebsd.org/pub/FreeBSD/releases/VM-IMAGES/11.2-RELEASE/amd64/Latest/FreeBSD-11.2-RELEASE-amd64.qcow2.xz && /bin/rm FreeBSD-11.2-RELEASE-amd64.qcow2 && unxz -k FreeBSD-11.2-RELEASE-amd64.qcow2.xz
