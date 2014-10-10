#!/bin/bash
# CPU Usage Totals Per VPS Per CPU/Core
# Written by Joe Huss <detain@interserver.net>
# - This is just way better than anything else out there for gathering this info
IFS="
";
if [ -e cpu_usage.last.sh ]; then
	. cpu_usage.last.sh;
else
	declare -A cputotals;
	declare -A cpuidles;
fi;
totalstring="declare -A cputotals=(";
idlestring="declare -A cpuidles=(";
prev=""
for i in $(grep "^cpu" /proc/vz/fairsched/*/cpu.proc.stat | tr / " "  | tr : " " | awk '{ print $4 " " $6 " " $7 " " $8 " " $9 " " $10 " " $11 " " $12 " " $13 " " $14 }'); do
	vzid="$(echo "$i" | awk '{ print $1 }')";
	cpu="$(echo "$i" | awk '{ print $2 }')";
	total="$(echo "$i" | awk '{ print $3 "+" $4 "+" $5 "+" $6 "+" $7 "+" $8 "+" $9}' |bc -l)";
	idle="$(echo "$i" | awk '{ print $6 }')";
	key="${vzid}_${cpu}";
	totalstring="${totalstring}[${key}]=\"${total}\" ";
	idlestring="${idlestring}[${key}]=\"${idle}\" ";
	if [ ! -z "${cputotals[${key}]}" ]; then
		cputotal=$((${total} - ${cputotals[${key}]}));
		cpuidle=$((${idle} - ${cpuidles[${key}]}));
		usage="$(echo "100 - (${cpuidle} / ${cputotal} * 100)" | bc -l)";
		usage="$(echo "scale=2; ${usage}/1" | bc -l)";
		if [ "${prev}" != "${vzid}" ]; then
			if [ "${prev}" != "" ]; then
				echo "";
			fi;
			echo -n "$vzid";
		fi;
		prev="${vzid}";
		echo -n " $cpu ${usage}";
		#echo "$vzid $cpu ${usage}%";
	fi;
done
echo "";
totalstring="${totalstring});\nexport cputotals;\n";
idlestring="${idlestring});\nexport cpuidles;\n";
echo -e "#\!/bin/bash\n${totalstring}${idlestring}" > cpu_usage.last.sh;
chmod +x cpu_usage.last.sh;
#DISP_SYS_RATE=$(echo "scale=${SCALE}; ${SYS_RATE}/1 "| bc);
