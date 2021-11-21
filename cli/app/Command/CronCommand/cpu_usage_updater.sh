#!/bin/bash
# CPU Usage Updater - by Joe Huss <detain@interserver.net>
#  Updates the CPU Usage at periodic intervals (10).  It measures the time
#  spent each time getting + updating the usage, and if it ran faster than
#  the interval time, it sleeps for the difference.  It repeats this entire
#  process until a the total time spent is equal or greater than maxtime (60).
base="$(readlink -f "$(dirname "$0")")";
start=$(date +%s);
new=${start};
last=${start};
spent=0;
interval=30;
maxtime=35;
showts=0;
[ $showts -eq 1 ] && echo -n "${new} ";
echo "Getitng CPU usage every ${interval} seconds for the next ${maxtime} seconds";
while [ ${spent} -lt ${maxtime} ]; do
	new=$(date +%s);
	first=$new;
	prev=$new;
	[ $showts -eq 1 ] && echo -n "${new} ";
	echo -n "Grabbing";
	#cpu_usage="$(${base}/cpu_usage.sh -json| sed s#"\""#"\&quot;"#g)";
	cpu_usage="$(${base}/cpu_usage.sh -json)";
	new=$(date +%s);
	lastspent=$((${new} - ${prev}));
	prev=$new;
	echo -n "(${lastspent}s),Sending";
	#curl --connect-timeout 60 --max-time 600 -k -F action=cpu_usage -F "cpu_usage=${cpu_usage}" "https://mynew.interserver.net/vps_queue.php" 2>/dev/null;
	curl --connect-timeout 60 --max-time 600 -k -F action=cpu_usage -F "cpu_usage=${cpu_usage}" "http://mynew.interserver.net:55151/queue.php" 2>/dev/null;
	new=$(date +%s);
	lastspent=$((${new} - ${prev}));
	prev=$new;
	echo -n "(${lastspent}s),Checking"
	lastspent=$((${new} - ${first}));
	spent=$((${new} - ${start}));
	echo -n "(${lastspent}s)"
	if [ ${lastspent} -lt ${interval} ]; then
		speedy=$((${interval} - ${lastspent}));
		echo -n ",Sleeping(${speedy}s)"
		sleep ${speedy}s;
		spent=$((${spent} + ${speedy}));
	fi;
	echo ",Overall(${spent}/${maxtime}s),Left($((${maxtime} - ${spent}))s)";
	last=${new};
done;
