#!/usr/bin/env bash
#
# https://libguestfs.org/virt-builder.1.html
#
IFS="
"
ext=qcow2
format=qcow2
#export http_proxy=http://64.20.46.218:8000
#export https_proxy=http://64.20.46.218:8000
#export ftp_proxy=http://64.20.46.218:8000
if [ "$1" = "" ]; then
	echo "$0 <raw|qcow2>"
	echo " raw|qcow2 - specifies the output image format, defaults to qcow2, craetes .img/.qcow2 image files"
	exit
fi
format=$1
ext=$1
if [ "$1" = "raw" ]; then
	ext=img
fi
shift
if [ "$1" != "" ]; then
	templates="$(virt-builder -l|sort|grep "$1")"
	shift
else
	templates="$(virt-builder -l|sort)"
fi
created=""
for i in ${templates}; do
	tag="$(echo "$i"|cut -d" " -f1)"
	arch="$(echo "$i"|awk '{ print $2 }')"
	archfile="$arch";
	label="$(echo "$i"|sed s#"^[^ ]* *[^ ]* *"#""#g)"
	os="$(echo "$tag"|cut -d- -f1)"
	version="$(echo "$tag"|cut -d- -f2-)"
	cmd="virt-builder --network --colors -m 2048 --smp 8 --format ${format} --arch ${arch} -o ${tag}.${ext}"
	cmd="${cmd} --root-password 'password:interserver123'"
	cmd="${cmd} --ssh-inject 'root:string:from=\"66.45.228.251\" ssh-rsa AAAAB3NzaC1yc2EAAAABIwAAAgEAvuKNsgUCyIoXcpiYkfOikuzlY1TlGGKgU6jMqEu/abStxgncwIX6eV19F5WAl8WYFbpOaolIFAR1Slxd2t7FuSK9B9BGqLNYdhwOLd75EPK71gAbnE2proZvOkuVSNb6Eq6ZHzlWiRVISXZyeGfMiJWr8/BDaIOJQaUUJ5/PcLOcuvpQxqslCninf2usswNQ6feRgYRbebgY6ydBuWpvf1moTxBogAVkh5cvdmGFmFlK5L2OMnJJgfwaLHkE//F60CU5LTaZPMuK/DEM0TyPBKdNAR+4oNiw3NdX/CzCq8VPZyjaIpNkGCsMgZGC4gYcY7TXSOek+870ONGaPKKcQJVJe3IE48zeGQSAUe4FoZwoGVvOMuyM1Lh7986Q6Co8zLiGUOfvfD08kmsCtRuRhA04VigVKEEY/b1zS8T4wC1slb77HhbTL+Q0rF84rh0m0pZ2BFUDwpM64shsTfy7JVr8akN7A68UMA5yT/G7U0o3YsZW/Q0dmu/KaOv/s1sJ1Fhie/om5qsg31qZr1R9GyiOCq3qB5ZC8J8sH3ZKhHEH5ulO6nf6J02WIYJJUuIu2CSqlsvOWNwgp5z1H2T0HA407cetqRcGH+4ymBvXiLcPZTRi5wO/QGBX1NvyNP2MFaASeNm+EIvWXlQVVXnHIT5UdPLYHVv+L+YHkOT185k= root@tech.trouble-free.net'"
	if [ "$os" != "cirros" ]; then
		cmd="${cmd} --edit '/etc/ssh/sshd_config: s{^#PermitRootLogin}{PermitRootLogin}; s{^PermitRootLogin.*$}{PermitRootLogin yes};'"
	fi
	cmd="${cmd} --hostname=${os}.is.cc"
	if [ "$arch" != "x86_64" ]; then
		continue
	fi;
	case $os in
	"centos")
		if [ "$version" = "6" ]; then
			cmd="${cmd} --edit '/etc/yum.repos.d/CentOS-Base.repo: s{^mirrorlist=}{#mirrorlist=}; s{^#baseurl=}{baseurl=}; s{https://}{http://}; s{mirror.centos.org}{linuxsoft.cern.ch/centos-vault};'"
		elif [ $(echo "$version"|sed "s#[^0-9]##g") -le 73 ]; then
			cmd="${cmd} --edit '/etc/yum.repos.d/CentOS-Base.repo: s{^mirrorlist=}{#mirrorlist=}; s{^#baseurl=}{baseurl=}; s{https://}{http://}; s{mirror.centos.org}{linuxsoft.cern.ch};'"
		else
			cmd="${cmd} --edit '/etc/yum.repos.d/CentOS-Base.repo: s{^mirrorlist=}{#mirrorlist=}; s{^#baseurl=}{baseurl=}; s{mirror.centos.org}{mirror.trouble-free.net};'"
		fi;
		h=mirror.trouble-free.net
		cmd="${cmd} --append-line '/etc/hosts:$(host $h|grep "has address"|head -n 1|cut -d" " -f4) $h'";
		cmd="${cmd} --append-line '/etc/sysconfig/network-scripts/ifcfg-eth0:DEVICE=eth0'";
		cmd="${cmd} --install nano,psmisc,wget,rsync,net-tools"
		cmd="${cmd} --update";
		cmd="${cmd} --selinux-relabel"
		;;
	"fedora")
		for h in mirrors.fedoraproject.org dl.fedoraproject.org mirrors.rit.edu mirrors.kernel.org; do
			cmd="${cmd} --append-line '/etc/hosts:$(host $h|grep "has address"|head -n 1|cut -d" " -f4) $h'";
		done;
		cmd="${cmd} --install nano,psmisc,wget,rsync,net-tools"
		cmd="${cmd} --update";
		if [ $(echo "$version"|sed "s#[^0-9]##g") -le 20 ] || [ $(echo "$version"|sed "s#[^0-9]##g") -ge 31 ]; then
			cmd="${cmd} --edit '/etc/selinux/config: s/SELINUX=enforcing/SELINUX=disabled/'"
		else
			cmd="${cmd} --selinux-relabel"
		fi
		;;
	"opensuse")
		h=download.opensuse.org
		cmd="${cmd} --append-line '/etc/hosts:$(host $h|grep "has address"|head -n 1|cut -d" " -f4) $h'";
		if [ "$(echo ${version:0:1}|grep "[0-9]")" != "" ]; then
			cmd="${cmd} --update";
		fi
		;;
	"scientificlinux")
		h=ftp.scientificlinux.org
		cmd="${cmd} --edit '/etc/yum.repos.d/sl-other.repo: s{linux/scientific}{linux/scientific/obsolete};'"
		cmd="${cmd} --edit '/etc/yum.repos.d/sl6x.repo: s{linux/scientific}{linux/scientific/obsolete};'"
		cmd="${cmd} --edit '/etc/yum.repos.d/sl.repo: s{linux/scientific}{linux/scientific/obsolete};'"
		cmd="${cmd} --append-line '/etc/hosts:$(host $h|grep "has address"|head -n 1|cut -d" " -f4) $h'"
		cmd="${cmd} --update"
		;;
	"debian" | "ubuntu")
		if [ "$version" != "6" ] && [ "$version" != "7" ]; then
			cmd="${cmd} --install nano,psmisc,wget,rsync,net-tools"
		fi;
		cmd="${cmd} --firstboot-command 'dpkg-reconfigure openssh-server'";
		if [ "$version" != "6" ] && [ "$version" != "7" ] && [ "$version" != "8" ] && [ "$version" != "10.04" ] && [ "$version" != "12.04" ] && [ "$version" != "14.04" ]; then
			cmd="${cmd} --update";
		fi
		;;
	esac;
	cmd="${cmd} ${tag} $*"
	#echo "Building/Updating Tag: ${tag}  Arch: ${arch}  Label: ${label}  With:"
	echo -e "${cmd}" >> commands.txt;
	#echo -e "$(echo "${cmd}"|sed s#" --"#" \\\\\n    --"#g)";
	eval $cmd || touch ${tag}.failed;
	if [ ! -e "${tag}.${ext}" ]; then
		echo $tag >> errors.txt
	fi
	created="${created} ${tag} ";
done

for i in ubuntu-16.04 ubuntu-18.04 ubuntu-20.04 debian-7 debian-8 debian-9 debian-10 debian-11; do
	if [ "$(echo "${created}"|grep " ${i} ")" = "" ]; then
		continue;
	fi;
	compressed=0;
	if [ -e ${i}.${ext}.gz ]; then
		compressed=1
		gunzip ${i}.${ext}.gz
	fi && \
	if [ -e ${i}.${ext} ]; then
		echo "Working on ${i}.${ext}";
		cmd="virt-customize -a ${i}.${ext}"
		cmd="${cmd} $(virt-ls -a ${i}.${ext} -R /etc|grep -e "/interfaces$" -e netplan/|sed s#"^\(.*\)$"#"--edit '/etc\1: s/(ens2|ens3|enp1s0)/eth0/'"#g|tr "\n" " ")"
		cmd="${cmd} --edit '/etc/default/grub: s/^GRUB_CMDLINE_LINUX=\"/GRUB_CMDLINE_LINUX=\"net.ifnames=0 biosdevname=0 /' --run-command 'update-grub2'"
		eval ${cmd}
	fi && \
	if [ $compressed -eq 1 ]; then
		gzip -9 ${i}.${ext}
	fi
done
for i in centos-8.0 centos-8.2; do
	if [ "$(echo "${created}"|grep " ${i} ")" = "" ]; then
		continue;
	fi;
	compressed=0;
	if [ -e ${i}.${ext}.gz ]; then
		compressed=1
		gunzip ${i}.${ext}.gz
	fi && \
	if [ -e ${i}.${ext} ]; then
		echo "Working on ${i}.${ext}";
		cmd="virt-customize -a ${i}.${ext}"
		cmd="${cmd} --edit '/etc/selinux/config: s/SELINUX=enforcing/SELINUX=permissive/'"
		if [ "$(virt-ls -a ${i}.${ext} /etc/sysconfig/network-scripts/|grep ifcfg-enp1s0)" != "" ]; then
			cmd="${cmd} --move '/etc/sysconfig/network-scripts/ifcfg-enp1s0:/etc/sysconfig/network-scripts/ifcfg-ens3'"
			cmd="${cmd} --edit '/etc/sysconfig/network-scripts/ifcfg-ens3: s/enp1s0/ens3/'"
			eval ${cmd}
		fi;
	fi && \
	if [ $compressed -eq 1 ]; then
		gzip -9 ${i}.${ext}
	fi
done
