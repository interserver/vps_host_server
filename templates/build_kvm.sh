#!/usr/bin/env bash
#
# Links/Pages
# https://libguestfs.org/virt-builder.1.html
# https://arstech.net/centos-6-error-yumrepo-error-all-mirror-urls-are-not-using-ftp-http/
# http://centosquestions.com/yum-update-giving-errno-14-problem-making-ssl-connection/
# http://linuxsoft.cern.ch/centos-vault/6.10/os/x86_64/
# https://unix.stackexchange.com/questions/109585/yum-update-fails-error-cannot-retrieve-repository-metadata-repomd-xml-for-re
# https://unix.stackexchange.com/questions/225549/qemu-guest-agent-for-ubuntu-12-04-lts
# https://launchpad.net/ubuntu/+source/qemu/2.0.0+dfsg-2ubuntu1.46
# https://pve.proxmox.com/wiki/Qemu-guest-agent
# https://wiki.qemu.org/Features/GuestAgent
# https://wiki.libvirt.org/page/Qemu_guest_agent
#
#
# Successfully Builds
#  CentOS 6, 7.0, 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.8, 8.0, 8.2
#  Debian 6 7 8 9 10 11
#  Fedora 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 34, 35
#  openSuSe 13.1 13.2 42.1 tumbleweed
#  ScientificLinux 6
#  Ubuntu 10.04 12.04 14.04 16.04 18.04 20.04
#
# Unsuccessful Builds
#  Fedora 19 33
#
# Fedora grub2 boot lines
#  load_video
#  set gfxpayload=keep
#  insmod gzio
#  linux ($root)/vmlinuz-<ver>.x86_64 root=UUID=<UUID> ro console=tty0 rd_NO_PLYMOUTH console=ttyS0,115200
#  initrd ($root)/initramfs-<ver>.x86_64.img
#
# Kernels
#  F33 5.14.18-100.fc33
#  F34 5.15.6-100.fc34
#
# Qemu Agent Package Names
#  Fedora		qemu-guest-agent
#  Ubuntu		qemu-guest-agent
#

IFS="
"
ext=qcow2
format=qcow2
verbose=""
#export http_proxy=http://64.20.46.218:8000
#export https_proxy=http://64.20.46.218:8000
#export ftp_proxy=http://64.20.46.218:8000
if [ "$1" = "" ]; then
	echo "$0 <raw|qcow2> [-v] [os]"
	echo " raw|qcow2 - specifies the output image format, defaults to qcow2, craetes .img/.qcow2 image files"
	exit
fi
format=$1
ext=$1
if [ "$1" = "raw" ]; then
	ext=img
fi
shift
if [ "$1" = "verb" ]; then
	verbose="-v"
	shift
fi
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
	version="$(echo "$tag"|cut -d- -f2-)" 											# ie 12.04 (string)
	verNum="$(echo "$version"|sed "s#[^0-9]##g")" 									# 12.04 => 1204 (int)
	verInt="$(echo "$version"|sed -e "s#^([^\.]*)\..*$#\1#g" -e "s#[^0-9]##g")" 	# 12.04 => 12 (int)
	cmd="virt-builder ${verbose} --network --colors -m 2048 --smp 8 --format ${format} --arch ${arch} -o ${tag}.${ext}"
	cmd="${cmd} --root-password 'password:interserver123'"
	cmd="${cmd} --ssh-inject 'root:string:$(grep -h root@tech ~/.ssh/authorized_keys*)'"
	if [ "$os" = "cirros" ]; then
		continue;
		cmd="${cmd} --edit '/etc/ssh/sshd_config: s{^#PermitRootLogin}{PermitRootLogin}; s{^PermitRootLogin.*$}{PermitRootLogin yes};'"
	fi
	cmd="${cmd} --hostname=${os}.is.cc"
	if [ "$arch" != "x86_64" ] || [ "$os" = "cirros" ]; then
		continue;  # only building 64bit templates, and cirros is just the kernel / barebones .. not even a package manager or ssh so skipping it
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
		cmd="${cmd} --install nano,psmisc,wget,rsync,net-tools,qemu-guest-agent"
		cmd="${cmd} --update";
		cmd="${cmd} --selinux-relabel"
		;;
	"fedora")
		for h in mirrors.fedoraproject.org dl.fedoraproject.org mirrors.rit.edu mirrors.kernel.org; do
			cmd="${cmd} --append-line '/etc/hosts:$(host $h|grep "has address"|head -n 1|cut -d" " -f4) $h'";
		done;
		if [ $(echo "$version"|sed "s#[^0-9]##g") -gt 20 ]; then
			cmd="${cmd} --install nano,psmisc,wget,rsync,net-tools,qemu-guest-agent"
			#if [ "$version" != "33" ] && [ "$version" != "34" ]; then
				cmd="${cmd} --update";
			#else
				#cmd="${cmd} --run-command 'yum update -y'"
			#fi
		fi
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
	"debian")
		#if [ "$version" != "6" ] && [ "$version" != "7" ]; then
			cmd="${cmd} --install nano,psmisc,wget,rsync,net-tools"
		#fi;
		cmd="${cmd} --install qemu-guest-agent"
		#if [ "$version" != "6" ] && [ "$version" != "7" ] && [ "$version" != "8" ]; then
			cmd="${cmd} --update";
		#fi
		firstBoot="dpkg-reconfigure openssh-server";
		cmd="${cmd} --firstboot-command '${firstBoot}'";
		;;
	"ubuntu")
		if [ "$version" = "10.04" ] || [ "$version" = "12.04" ]; then
			cmd="${cmd} --edit '/etc/apt/sources.list: s/extras.ubuntu.com/old-releases.ubuntu.com/'"
			cmd="${cmd} --edit '/etc/apt/sources.list: s/security.ubuntu.com/old-releases.ubuntu.com/'"
			cmd="${cmd} --edit '/etc/apt/sources.list: s/us.archive.ubuntu.com/old-releases.ubuntu.com/'"
			cmd="${cmd} --edit '/etc/apt/sources.list: s/archive.ubuntu.com/old-releases.ubuntu.com/'"
		fi
		cmd="${cmd} --install nano,psmisc,wget,rsync,net-tools"
		if [ "$version" != "10.04" ] && [ "$version" != "12.04" ] && [ "$version" != "14.04" ]; then
			cmd="${cmd} --install qemu-guest-agent"
		fi
		if [ "$version" != "10.04" ] && [ "$version" != "12.04" ] && [ "$version" != "14.04" ]; then
			cmd="${cmd} --update";
		fi
		firstBoot="dpkg-reconfigure openssh-server";
		if [ "$version" = "10.04" ]; then
			firstBoot="${firstBoot};apt-get update; apt-get dist-upgrade -y; apt-get autoremove -y --purge; apt-get clean"
        fi
		cmd="${cmd} --firstboot-command '${firstBoot}'";
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
