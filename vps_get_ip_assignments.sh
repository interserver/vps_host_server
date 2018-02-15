#!/bin/bash
# Gets a list of all the IPs used by each VPS and sends them
export PATH="$PATH:/usr/sbin:/sbin:/bin:/usr/bin";
if [ "$(which vzctl 2>/dev/null)" != "" ]; then
	for i in $(grep -l "^IP_ADDRESS=\"[^\\\"].*\"" /etc/vz/conf/*.conf); do
		source $i;
		echo "$VEID $(echo $IP_ADDRESS|sed s#"/255.255.255.0"#""#g)";
	done|sort|uniq
	#grep -H "^IP_ADDRESS" /etc/vz/conf/[0-9a-z-]*.conf 2>/dev/null | grep -v -e "IP_ADDRESS=\"\"" -e "^#" | sed -e s#"^.*/\([0-9a-z-]*\)\.conf:IP_ADDRESS=\"\([-0-9\. :a-f\/]*\)\""#"\1 \2"#g -e s#"/255.255.255.0"#""#g;
fi;
#Sample output:
#100 69.10.46.222 209.159.155.10
#101 69.10.46.219
