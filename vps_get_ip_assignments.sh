#!/bin/bash
# Gets a list of all the IPs used by each VPS and sends them
export PATH="$PATH:/usr/sbin:/sbin:/bin:/usr/bin";
if [ "$(which vzctl 2>/dev/null)" != "" ]; then
	grep "^IP_ADDRESS" /etc/vz/conf/[0-9]*.conf 2>/dev/null | sed s#"^.*/\([0-9]*\)\.conf:IP_ADDRESS=\"\([0-9\. :a-f\/]*\)\""#"\1 \2"#g;
fi;
#Sample output:
#100 69.10.46.222 209.159.155.10
#101 69.10.46.219
