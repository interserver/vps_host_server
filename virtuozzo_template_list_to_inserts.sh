#!/bin/bash
# Script to build a list of SQL Insert statements to populate the Virtuozzo template list
for i in $(vzpkg list -O); do
	o="$(echo "$i"|cut -d- -f1)";
	v="$(echo "$i" | cut -d- -f2)";
	a="$(echo "$i"|cut -d- -f2- |sed -e s#"-x86_64"#""#g -e s#"-"#" "##g)";
	n="$(echo "$o" | sed -e s#"centos"#"CentOS"#g -e s#"ubuntu"#"Ubuntu"#g -e s#"suse"#"OpenSuse"#g -e s#"debian"#"Debian"#g -e s#"fedora"#"Fedora"#g -e s#"vzlinux"#"VzLinux"#g)"
	echo "insert into vps_templates values (NULL,12,'$o','$a',64,'$i',1,'$n','');";
done
