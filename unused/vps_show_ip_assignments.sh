#!/bin/bash
# Gets a list of all the IPs used by each VPS and sends them

grep IP_ADDRESS /etc/vz/conf/*conf | sed s#"^.*/\([0-9]*\)\.conf:IP_ADDRESS=\"\([0-9\. ]*\)\""#"\1 \2"#g
#Sample output:
#100 69.10.46.222 209.159.155.10
#101 69.10.46.219
