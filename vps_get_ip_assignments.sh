#!/bin/bash
# Gets a list of all the IPs used by each VPS and sends them
export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/usr/sbin:/sbin:/bin:/usr/bin";
if [ "$(which prlctl 2>/dev/null)" != "" ]; then
	for i in $(grep -l "^IP_ADDRESS=\"[^\\\"].*\"" /etc/vz/conf/*.conf); do
        unset VEID;
        unset NAME;
		unset UUID;
		source $i;
        if [ -v NAME ]; then
            echo "$NAME $(echo $IP_ADDRESS|sed s#"/255.255.255.0"#""#g)";
        elif [ -v VEID ]; then
            echo "$VEID $(echo $IP_ADDRESS|sed s#"/255.255.255.0"#""#g)";
		elif [ -v UUID ]; then
			echo "$UUID $(echo $IP_ADDRESS|sed s#"/255.255.255.0"#""#g)";
		else
			echo "$(basename $i .conf) $(echo $IP_ADDRESS|sed s#"/255.255.255.0"#""#g)";
		fi
	done|sort|uniq
elif [ "$(which vzctl 2>/dev/null)" != "" ]; then
	grep -H "^IP_ADDRESS" /etc/vz/conf/[0-9a-z-]*.conf 2>/dev/null | grep -v -e "IP_ADDRESS=\"\"" -e "^#" | sed -e s#"^.*/\([0-9a-z-]*\)\.conf:IP_ADDRESS=\"\([-0-9\. :a-f\/]*\)\""#"\1 \2"#g -e s#"/255.255.255.0"#""#g;
fi;
#Sample output:
#100 69.10.46.222 209.159.155.10
#101 69.10.46.219
