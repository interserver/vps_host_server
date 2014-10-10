#!/bin/bash
# CPU Usage Totals Per VPS Per CPU/Core
# Written by Joe Huss <detain@interserver.net>
# - This is just way better than anything else out there for gathering this info
IFS="
";
if [ -e ~/.cpu_usage.last.sh ]; then
	source ~/.cpu_usage.last.sh;
else                            
	if [ ${BASH_VERSION:0:1} -ge 4 ]; then
		declare -A cputotals;
		declare -A cpuidles;
	fi;
fi;
if [ "${1}" = "-serialize" ]; then
	out=serialize;
elif [ "${1}" = "-json" ]; then
	out=json;
else
	out=normal;
fi;
if [ ${BASH_VERSION:0:1} -lt 4 ]; then
	totalstring="";
	idlestring="";
else
	totalstring="declare -A cputotals=(";
	idlestring="declare -A cpuidles=(";
fi;
prev="";
if [ "$out" = "json" ]; then
	echo -n "{";
fi;
for i in $(grep "^cpu" /proc/vz/fairsched/*/cpu.proc.stat | tr / " "  | tr : " " | awk '{ print $4 " " $6 " " $7 " " $8 " " $9 " " $10 " " $11 " " $12 " " $13 " " $14 }'); do
	vzid="$(echo "$i" | awk '{ print $1 }')";
	cpu="$(echo "$i" | awk '{ print $2 }')";
	total="$(echo "$i" | awk '{ print $3 "+" $4 "+" $5 "+" $6 "+" $7 "+" $8 "+" $9}' |bc -l)";
	idle="$(echo "$i" | awk '{ print $6 }')";
	key="${vzid}_${cpu}";
	if [ ${BASH_VERSION:0:1} -lt 4 ]; then
		tkey="idle_${key}";
		ikey="idle_${key}";
		totalstring="${totalstring}export ${tkey}=\"${total}\";\n";
		idlestring="${idlestring}export ${ikey}=\"${idle}\";\n";
	else
		tkey="cputotals[${key}]";
		ikey="cpuidles[${key}]";
		totalstring="${totalstring}[${key}]=\"${total}\" ";
		idlestring="${idlestring}[${key}]=\"${idle}\" ";
	fi;
	if [ ! -z "$$tkey" ]; then
		cputotal=$((${total} - $$tkey));
		cpuidle=$((${idle} - $$ikey));
		usage="$(echo "100 - (${cpuidle} / ${cputotal} * 100)" | bc -l)";
		usage="$(echo "scale=2; ${usage}/1" | bc -l)";
		if [ "${usage:0:1}" = "." ]; then
			usage="0${usage}";
		fi;
		if [ "${prev}" != "${vzid}" ]; then
			if [ "${prev}" != "" ]; then
				if [ "$out" = "json" ]; then
					echo -n "},";
				else
					echo "";
				fi;
			fi;
			if [ "$out" = "json" ]; then
				echo -n "\"${vzid}\":{";
			else
				echo -n "$vzid";
			fi;
		fi;
		prev="${vzid}";
		if [ "$out" = "json" ]; then
			if [ "${cpu}" != "cpu" ]; then
				echo -n ",";
			fi;
			echo -n "\"${cpu}\":${usage}";
		else
			echo -n " $cpu ${usage}";
		fi;
		#echo "$vzid $cpu ${usage}%";
	fi;
done
if [ "$out" = "json" ]; then
	echo "}}";
else
	echo "";
fi;
if [ ${BASH_VERSION:0:1} -lt 4 ]; then
	totalstring="${totalstring}\n";
	idlestring="${idlestring}\n";
else
	totalstring="${totalstring});\nexport cputotals;\n";
	idlestring="${idlestring});\nexport cpuidles;\n";
fi;
echo -e "#!/bin/bash\n${totalstring}${idlestring}" > ~/.cpu_usage.last.sh;
