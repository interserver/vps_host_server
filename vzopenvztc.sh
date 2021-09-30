#!/bin/sh

if [ -e /tools/disabletc ]; then
	exit;
fi

if [ ! -e /etc/vz/conf ]; then
	exit;
fi

cat <<EOF
#!/bin/sh

export PATH=/usr/local/bin:/usr/local/sbin:$PATH:/sbin

modprobe ipt_mark
modprobe ipt_MARK
modprobe ipt_MASQUERADE
modprobe ipt_helper
modprobe ipt_REDIRECT
modprobe ipt_state
modprobe ipt_TCPMSS
modprobe ipt_LOG
#modprobe ipt_SAME
modprobe ipt_TOS
modprobe iptable_nat
modprobe ipt_length
modprobe ipt_tcpmss
modprobe iptable_mangle
modprobe ipt_limit
modprobe ipt_tos
modprobe iptable_filter
modprobe ipt_helper
modprobe ipt_tos
modprobe ipt_ttl
modprobe ipt_REJECT

#modprobe sch_sfq
##modprobe em_u32
#modprobe cls_u32
#modprobe sch_cbq
##modprobe sch_prio


#eth
/sbin/tc qdisc del dev eth0 root
/sbin/tc qdisc add dev eth0 root handle 1: cbq avpkt 1000 bandwidth 200mbit

#venet
/sbin/tc qdisc del dev venet0 root
/sbin/tc qdisc add dev venet0 root handle 1: cbq avpkt 1000 bandwidth 200mbit

# vps's / changes here


EOF

nid=1;

if [ -e /etc/vz/conf ]; then
        cd /etc/vz/conf
        # ignore ve-vps.basic.conf
        for i in `ls *.conf | grep -v ve-vps.basic.conf`; do
                id=`echo $i | cut -d. -f1`;
                IPs=`cat $i | grep ^IP_A | cut -d\" -f2`
		BW='10mbit'
                # ignore files with no ips
                if [ ! "$IPs" = "" ]; then
			# look for BW upgrade
			if [ -e /tools/bandwidth/$id/80 ]; then
                                BW='80mbit'
			elif [ -e /tools/bandwidth/$id/30 ]; then
                                BW='30mbit'
			elif [ -e /tools/bandwidth/$id/40 ]; then
                                BW='40mbit'
			elif [ -e /tools/bandwidth/$id/50 ]; then
                                BW='50mbit'
			elif [ -e /tools/bandwidth/$id/60 ]; then
                                BW='60mbit'
			elif [ -e /tools/bandwidth/$id/70 ]; then
                                BW='70mbit'
			elif [ -e /tools/bandwidth/$id/20 ]; then
				BW='20mbit'
			elif [ -e /tools/bandwidth/$id/5 ]; then
				BW='5mbit'
			elif [ -e /tools/bandwidth/$id/10 ]; then
				BW='10mbit'
			elif [ -e /tools/bandwidth/$id/15 ]; then
				BW='15mbit'
			elif [ -e /tools/bandwidth/$id/25 ]; then
				BW='25mbit'
			# just in case nothing passed
			else
				BW='25mbit'
			fi
                        for ip in $IPs; do
				if [ ! -e /tools/bandwidth/$id/skip ]; then
                                	echo "#VZID $id";
                                	echo '#eth0 up';
                                	echo "/sbin/tc class add dev eth0 parent 1: classid 1:$nid cbq rate $BW allot 1500 prio 5 bounded isolated";
                                	echo "/sbin/tc filter add dev eth0 parent 1: protocol ip prio 16 u32 match ip src $ip flowid 1:$nid";
                                	echo "/sbin/tc qdisc add dev eth0 parent 1:$nid sfq perturb 1";
                                	nid=`expr $nid + 1`;
                                	echo '#eth0 down';
					echo "/sbin/tc class add dev venet0 parent 1: classid 1:$nid cbq rate $BW allot 1500 prio 5 bounded isolated"
                                	#echo "/sbin/tc filter add dev venet0 parent 1: protocol ip prio 16 u32 match ip src $ip flowid 1:$nid";
                                	echo "/sbin/tc filter add dev venet0 parent 1: protocol ip prio 16 u32 match ip dst $ip flowid 1:$nid";
                                	echo "/sbin/tc qdisc add dev venet0 parent 1:$nid sfq perturb 10";
					nid=`expr $nid + 1`;
				fi
                        done
                                echo

                fi
        done
else
        echo 'Error: /etc/vz/conf not found';
fi


