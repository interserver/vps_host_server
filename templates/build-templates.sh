#!/bin/bash
IFS="
"
ext=qcow2
format=qcow2
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
        templates="$(virt-builder -l|sort -n|grep "$1")"
else
	templates="$(virt-builder -l|sort -n)"
fi
for i in ${templates}; do
        tag="$(echo "$i"|cut -d" " -f1)"
        arch="$(echo "$i"|awk '{ print $2 }')"
        archfile="$arch";
        label="$(echo "$i"|sed s#"^[^ ]* *[^ ]* *"#""#g)"
        os="$(echo "$tag"|cut -d- -f1)"
        version="$(echo "$tag"|cut -d- -f2-)"
        cmd="virt-builder ${tag} --network --colors -m 2048 --smp 8 --format ${format} --arch ${arch} -o ${tag}.${ext} --edit '/etc/ssh/sshd_config: s{^#PermitRootLogin}{PermitRootLogin}; s{^PermitRootLogin.*$}{PermitRootLogin yes};' --root-password 'password:interserver123' --ssh-inject 'root:string:from=\"66.45.228.251\" ssh-rsa AAAAB3NzaC1yc2EAAAABIwAAAgEAvuKNsgUCyIoXcpiYkfOikuzlY1TlGGKgU6jMqEu/abStxgncwIX6eV19F5WAl8WYFbpOaolIFAR1Slxd2t7FuSK9B9BGqLNYdhwOLd75EPK71gAbnE2proZvOkuVSNb6Eq6ZHzlWiRVISXZyeGfMiJWr8/BDaIOJQaUUJ5/PcLOcuvpQxqslCninf2usswNQ6feRgYRbebgY6ydBuWpvf1moTxBogAVkh5cvdmGFmFlK5L2OMnJJgfwaLHkE//F60CU5LTaZPMuK/DEM0TyPBKdNAR+4oNiw3NdX/CzCq8VPZyjaIpNkGCsMgZGC4gYcY7TXSOek+870ONGaPKKcQJVJe3IE48zeGQSAUe4FoZwoGVvOMuyM1Lh7986Q6Co8zLiGUOfvfD08kmsCtRuRhA04VigVKEEY/b1zS8T4wC1slb77HhbTL+Q0rF84rh0m0pZ2BFUDwpM64shsTfy7JVr8akN7A68UMA5yT/G7U0o3YsZW/Q0dmu/KaOv/s1sJ1Fhie/om5qsg31qZr1R9GyiOCq3qB5ZC8J8sH3ZKhHEH5ulO6nf6J02WIYJJUuIu2CSqlsvOWNwgp5z1H2T0HA407cetqRcGH+4ymBvXiLcPZTRi5wO/QGBX1NvyNP2MFaASeNm+EIvWXlQVVXnHIT5UdPLYHVv+L+YHkOT185k= root@tech.trouble-free.net'  --hostname=${os}.is.cc"
        if [ "$arch" != "x86_64" ] || [ "$os" = "freebsd" ]; then
                continue
        fi;
        case $os in
        "centos")
                h=mirror.trouble-free.net cmd="${cmd} --edit '/etc/yum.repos.d/CentOS-Base.repo: s{^mirrorlist=}{#mirrorlist=}; s{^#baseurl=}{baseurl=}; s{mirror.centos.org}{mirror.trouble-free.net};' --append-line '/etc/hosts:$(host $h|grep "has address"|head -n 1|cut -d" " -f4) $h' --append-line '/etc/sysconfig/network-scripts/ifcfg-eth0:DEVICE=eth0' --selinux-relabel --update";;
        "fedora")
                for h in mirrors.fedoraproject.org dl.fedoraproject.org mirrors.rit.edu mirrors.kernel.org; do
                        cmd="${cmd} --append-line '/etc/hosts:$(host $h|grep "has address"|head -n 1|cut -d" " -f4) $h'";
                done;
                cmd="${cmd} --selinux-relabel --update";;
        "opensuse")
                h=download.opensuse.org cmd="${cmd} --append-line '/etc/hosts:$(host $h|grep "has address"|head -n 1|cut -d" " -f4) $h'";
                if [ "$(echo ${version:0:1}|grep "[0-9]")" != "" ]; then
                        cmd="${cmd} --update";
                fi;;
        "scientificlinux")
                h=ftp.scientificlinux.org cmd="${cmd} --append-line '/etc/hosts:$(host $h|grep "has address"|head -n 1|cut -d" " -f4) $h' --update";;
        "debian" | "ubuntu")
                cmd="${cmd} --firstboot-command 'dpkg-reconfigure openssh-server' --update";;
        esac;
        #echo "Building/Updating Tag: ${tag}  Arch: ${arch}  Label: ${label}  With:"
        echo -e "$(echo "${cmd}"|sed s#" --"#" \\\\\n    --"#g)";
        eval $cmd $*;
        if [ ! -e "${tag}.${ext}" ]; then
                echo $tag >> errors.txt
        fi
done


# move templates to nginx system
#for template in `ls /root | grep qcow2`; do mv -v $template /var/www/html/$template; done

if [ "$format" = "qcow2" ]; then
        for i in ubuntu-16.04 ubuntu-18.04 debian-9; do
                guestmount -i -w -a ${i}.qcow2 /mnt;
                sed s#ens2#ens3#g -i /mnt/etc/network/interfaces;
                sed s#ens2#ens3#g -i /mnt/etc/netplan/01-netcfg.yaml;
                guestunmount /mnt;
        done
else
        for i in ubuntu-16.04 ubuntu-18.04 debian-9; do
                rm -f /var/www/html/raw/all/${i}.img.gz;
                gunzip ${i}.img.gz;
                guestmount -i -w -a ${i}.img /mnt;
                sed s#ens2#ens3#g -i /mnt/etc/network/interfaces;
                sed s#ens2#ens3#g -i /mnt/etc/netplan/01-netcfg.yaml;
                guestunmount /mnt;
                gzip -9 ${i}.img;
                ln -vP /var/www/html/raw/linux/${i}.img.gz /var/www/html/raw/all/${i}.img.gz;
        done
fi
