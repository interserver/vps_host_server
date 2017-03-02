#!/bin/bash

function iprogress() {
	curl --connect-timeout 60 --max-time 240 -k -d action=install_progress -d progress=$1 -d server=${id} 'https://myvps2.interserver.net/vps_queue.php' 2>/dev/null; 
}

while [[ $# -gt 1 ]]; do
	key="$1"
	for var in id template template_url config vzid ostemplate ip hostname cpuunits cpulimit cpus diskspace diskspace_b diskinodes diskinodes_b numproc numproc_b kmemsize kmemsize_b privvmpages privvmpages_b tcpsndbuf tcpsndbuf_b tcprcvbuf tcprcvbuf_b othersockbuf othersockbuf_b numtcpsock numtcpsock_b numothersock numothersock_b vmguarpages dgramrcvbuf dgramrcvbuf_b oomguarpages numfile numfile_b numflock numflock_b dcachesize dcachesize_b numiptent numiptent_b avnumproc avnumproc_b numpty numpty_b shmpages shmpages_b ram extraip rootpass ssh_key; do
		if [ "${key:0:${#var}+3}" = "--${var}=" ]; then
			export $var="${key:${#var}+3}"
			echo "Setting ${var} to ${key:${#var}+3}";
		elif [ "$key" = "--${var}" ]; then
			shift
			export $var="$1"
			echo "Setting ${var} to ${1}";
		fi
	done
	shift
done

iprogress 10 &
if [ ! -e /vz/template/cache/${template} ]; then 
  wget -O /vz/template/cache/${template} ${template_url}; 
fi;
iprogress 15 &
if [ "$(echo "${template}" | grep "xz$")" != "" ]; then
  newtemplate="$(echo "${template}" | sed s#"\.xz$"#".gz"#g)";
  if [ -e "/vz/template/cache/$newtemplate" ]; then
    echo "Already Exists in .gz, not changing anything";
  else
    echo "Recompressing ${template} to .gz";
    xz -d --keep "/vz/template/cache/${template}";
    gzip -9 "$(echo "/vz/template/cache/${template}" | sed s#"\.xz$"#""#g)";
  fi;
  template="$newtemplate";
fi;
iprogress 20 &
if [ "$(uname -i)" = "x86_64" ]; then 
  limit=9223372036854775807; 
else 
  limit=2147483647; 
fi
if [ "$(vzctl 2>&1 |grep "vzctl set.*--force")" = "" ]; then
  layout=""
  force=""
else
  if [ "$(mount | grep "^$(df /vz |grep -v ^File | cut -d" " -f1)" | cut -d" " -f5)" = "ext3" ]; then 
    layout=simfs; 
  else
    if [ $(echo "$(uname -r | cut -d\. -f1-2) * 10" | bc -l | cut -d\. -f1) -eq 26 ] && [ $(uname -r | cut -d\. -f3 | cut -d- -f1) -lt 32 ]; then 
      layout=simfs; 
    else 
      layout=ploop; 
    fi; 
  fi;
  layout="--layout $layout";
  force="--force"
fi;
if [ ! -e /etc/vz/conf/ve-vps.small.conf ] && [ -e /etc/vz/conf/ve-basic.conf-sample ]; then
  config="--config basic"
  config=""
else
  config="--config ${config}";
fi;
/usr/sbin/vzctl create ${vzid} --ostemplate ${ostemplate} $layout $config --ipadd ${ip} --hostname ${hostname} 2>&1 || \
 { 
    /usr/sbin/vzctl destroy ${vzid} 2>&1;
    if [ "$layout" == "--layout ploop" ]; then
      layout="--layout simfs";
    fi;
    /usr/sbin/vzctl create ${vzid} --ostemplate ${ostemplate} $layout $config --ipadd ${ip} --hostname ${hostname} 2>&1;
 }; 
  iprogress 40 &
  mkdir -p /vz/root/${vzid};

/usr/sbin/vzctl set ${vzid} --save $force --cpuunits ${cpuunits} --cpulimit ${cpulimit} --cpus ${cpus} \
   --diskspace ${diskspace}:${diskspace_b} --diskinodes ${diskinodes}:${diskinodes_b} --numproc ${numproc}:${numproc_b} \
   --kmemsize ${kmemsize}:${kmemsize_b} --privvmpages ${privvmpages}:${privvmpages_b} \
   --tcpsndbuf ${tcpsndbuf}:${tcpsndbuf_b} --tcprcvbuf ${tcprcvbuf}:${tcprcvbuf_b} --othersockbuf ${othersockbuf}:${othersockbuf_b} \
   --numtcpsock ${numtcpsock}:${numtcpsock_b} --numothersock ${numothersock}:${numothersock_b} --vmguarpages ${vmguarpages}:$limit \
   --dgramrcvbuf ${dgramrcvbuf}:${dgramrcvbuf_b} --oomguarpages ${oomguarpages}:$limit \
   --numfile ${numfile}:${numfile_b} --numflock ${numflock}:${numflock_b} --physpages 0:$limit --dcachesize ${dcachesize}:${dcachesize_b} \
   --numiptent ${numiptent}:${numiptent_b} --avnumproc ${avnumproc}:${avnumproc_b} --numpty ${numpty}:${numpty_b} \
   --shmpages ${shmpages}:${shmpages_b} 2>&1; 
if [ -e /proc/vz/vswap ]; then
  /bin/mv -f /etc/vz/conf/${vzid}.conf /etc/vz/conf/${vzid}.conf.backup;
#  grep -Ev '^(KMEMSIZE|LOCKEDPAGES|PRIVVMPAGES|SHMPAGES|NUMPROC|PHYSPAGES|VMGUARPAGES|OOMGUARPAGES|NUMTCPSOCK|NUMFLOCK|NUMPTY|NUMSIGINFO|TCPSNDBUF|TCPRCVBUF|OTHERSOCKBUF|DGRAMRCVBUF|NUMOTHERSOCK|DCACHESIZE|NUMFILE|AVNUMPROC|NUMIPTENT|ORIGIN_SAMPLE|SWAPPAGES)=' > /etc/vz/conf/${vzid}.conf <  /etc/vz/conf/${vzid}.conf.backup;
  grep -Ev '^(KMEMSIZE|PRIVVMPAGES)=' > /etc/vz/conf/${vzid}.conf <  /etc/vz/conf/${vzid}.conf.backup;
  /bin/rm -f /etc/vz/conf/${vzid}.conf.backup;  
  /usr/sbin/vzctl set ${vzid} --ram ${ram}M --swap ${ram}M --save;
  /usr/sbin/vzctl set ${vzid} --reset_ub;
fi;
iprogress 50 &
if [ -e /usr/sbin/vzcfgvalidate ]; then
 /usr/sbin/vzcfgvalidate -r /etc/vz/conf/${vzid}.conf;
fi;
/usr/sbin/vzctl set ${vzid} --save --devices c:1:3:rw --devices c:10:200:rw --capability net_admin:on;
/usr/sbin/vzctl set ${vzid} --save --nameserver '8.8.8.8 64.20.34.50' --searchdomain interserver.net --onboot yes;
/usr/sbin/vzctl set ${vzid} --save --noatime yes 2>/dev/null;
iprogress 60 &
{foreach item=extraip from=$extraips}
/usr/sbin/vzctl set ${vzid} --save --ipadd ${extraip} 2>&1;
{/foreach}
/usr/sbin/vzctl start ${vzid} 2>&1;
/usr/sbin/vzctl set ${vzid} --save --userpasswd root:${rootpass} 2>&1;
iprogress 80 &
/usr/sbin/vzctl exec ${vzid} mkdir -p /dev/net;
/usr/sbin/vzctl exec ${vzid} mknod /dev/net/tun c 10 200;
/usr/sbin/vzctl exec ${vzid} chmod 600 /dev/net/tun;
iprogress 90 &
/root/cpaneldirect/vzopenvztc.sh > /root/vzopenvztc.sh && sh /root/vzopenvztc.sh;
/usr/sbin/vzctl set ${vzid} --save --userpasswd root:${rootpass} 2>&1;
sshcnf="$(find /vz/root/${vzid}/etc/*ssh/sshd_config 2>/dev/null)";
if [ -e "$sshcnf" ]; then 
 if [ ! -z "${ssh_key}" ]; then
  vzctl exec ${vzid} "mkdir -p /root/.ssh;"
  vzctl exec ${vzid} "echo ${ssh_key} >> /root/.ssh/authorized_keys2;"
  vzctl exec ${vzid} "chmod go-w /root; chmod 700 /root/.ssh; chmod 600 /root/.ssh/authorized_keys2;"
 fi
 if [ "$(grep "^PermitRootLogin" $sshcnf)" = "" ]; then 
  echo "PermitRootLogin yes" >> $sshcnf; 
  echo "Added PermitRootLogin line in $sshcnf";
  kill -HUP $(vzpid $(pidof sshd) |grep "[[:space:]]${vzid}[[:space:]]" | sed s#"${vzid}.*ssh.*$"#""#g);
 elif [ "$(grep "^PermitRootLogin" $sshcnf)" != "PermitRootLogin yes" ]; then
  sed s#"^PermitRootLogin.*$"#"PermitRootLogin yes"#g -i $sshcnf;
  echo "Updated PermitRootLogin line in $sshcnf";
  kill -HUP $(vzpid $(pidof sshd) |grep "[[:space:]]${vzid}[[:space:]]" | sed s#"${vzid}.*ssh.*$"#""#g);
 fi;
fi;
if [ "${ostemplate}" = "centos-7-x86_64-nginxwordpress" ]; then
	vzctl exec ${vzid} /root/change.sh ${rootpass} 2>&1;
fi;

if [ "${ostemplate}" = "ubuntu-15.04-x86_64-xrdp" ]; then
	/usr/sbin/vzctl set ${vzid} --save --userpasswd kvm:${rootpass} 2>&1;
fi;
iprogress 100 &
