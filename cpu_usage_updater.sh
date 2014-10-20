#!/bin/bash
# CPU Usage Updater - by Joe Huss <detain@interserver.net>
#  Updates the CPU Usage at periodic intervals (10).  It measures the time 
#  spent each time getting + updating the usage, and if it ran faster than
#  the interval time, it sleeps for the difference.  It repeats this entire
#  process until a the total time spent is equal or greater than maxtime (60).
start=$(date +%s);
new=${start};
last=${start};
spent=0;
interval=10;
maxtime=60;
while [ ${spent} -lt ${maxtime} ]; do
	curl --connect-timeout 60 --max-time 240 -k -D action=cpu_usage \
		-D "cpu_usage=$(/root/cpaneldirect/cpu_usage.sh -serialize | sed s#"\""#"\&quot;"#g)" \
		"https://myvps2.interserver.net/vps_queue.php" 2>/dev/null;
	new=$(date +%s);
	lastspent=$((${new} - ${last}));
	spent=$((${new} - ${start}));
	if [ ${lastspent} -lt ${interval} ]; then
		speedy=$((${interval} - ${lastspent}));
		sleep ${speedy}s;
		spent=$((${spent} + ${speedy}));
	fi;
	last=${new};
done;
