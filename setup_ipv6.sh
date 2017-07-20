#!/bin/bash
read -p "Middle IPv6 Part 2604:a00:__:____::1/112 ?" ippart;
dev="$(route -n |grep ^0.0.0.0 | sed s#"^.* \([^ ]*\)$"#"\1"#g)";
yum install iptables-ipv6 ipv6calc subnetcalc -y;
grep -v -e IPV6INIT /etc/sysconfig/network-scripts/ifcfg-${dev} > /etc/sysconfig/network-scripts/ifcfg-${dev}.new
echo "IPV6INIT=yes
IPV6ADDR=2604:a00:${ippart}::2/112
IPV6_DEFAULTGW=2604:a00:${ippart}::1
" >> /etc/sysconfig/network-scripts/ifcfg-${dev}.new;
/bin/mv -f /etc/sysconfig/network-scripts/ifcfg-${dev}.new /etc/sysconfig/network-scripts/ifcfg-${dev};
grep -v -e IPV6 /etc/sysconfig/network > /etc/sysconfig/network.new;
echo "NETWORKING_IPV6=yes
IPV6_AUTOCONF=no
IPV6FORWARDING=yes" >> /etc/sysconfig/network.new;
/bin/mv -f /etc/sysconfig/network.new /etc/sysconfig/network;
/etc/init.d/network reload;
grep -v -e ipv6.conf.default.forwarding -e ipv6.conf.all.forwarding -e ipv6.conf.all.proxy_ndp -e ipv6.bindv6only /etc/sysctl.conf > /etc/sysctl.conf.new;
echo "net.ipv6.conf.default.forwarding = 1
net.ipv6.conf.all.forwarding = 1
net.ipv6.conf.all.proxy_ndp = 1
net.ipv6.bindv6only = 1" >> /etc/sysctl.conf.new;
/bin/mv -f /etc/sysctl.conf.new /etc/sysctl.conf;
sysctl -p;
ping6 ipv6.google.com ;
