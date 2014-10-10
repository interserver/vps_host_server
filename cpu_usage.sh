#!/bin/bash
# CPU Usage Totals Per VPS Per CPU/Core
# Written by Joe Huss <detain@interserver.net>
# - This is just way better than anything else out there for gathering this info
IFS="
";
debug=0;
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
elif [ "$out" = "serialize" ]; then
	output="";
	vzcount=0;
fi;
for i in $(grep "^cpu" /proc/vz/fairsched/*/cpu.proc.stat | tr / " "  | tr : " " | awk '{ print $4 " " $6 " " $7 " " $8 " " $9 " " $10 " " $11 " " $12 " " $13 " " $14 }'); do
	vzid="$(echo "$i" | awk '{ print $1 }')";
	cpu="$(echo "$i" | awk '{ print $2 }')";
	total="$(echo "$i" | awk '{ print $3 "+" $4 "+" $5 "+" $6 "+" $7 "+" $8 "+" $9}' |bc -l)";
	idle="$(echo "$i" | awk '{ print $6 }')";
	if [ "$debug" = "1" ]; then
		echo "Got VPS $vzid CPU $cpu Total $total Idle $idle";
	fi;
	key="${vzid}_${cpu}";
	haslast=0;
	if [ ${BASH_VERSION:0:1} -lt 4 ]; then
		totalstring="${totalstring}export total_${key}=\"${total}\";\n";
		idlestring="${idlestring}export idle_${key}=\"${idle}\";\n";
		if [ ! -z "$(eval echo "\${total_${key}}")" ]; then
			lasttotal=$(eval echo "\${total_${key}}");
			lastidle=$(eval echo "\${idle_${key}}");
			haslast=1;
		fi;
	else
		totalstring="${totalstring}[${key}]=\"${total}\" ";
		idlestring="${idlestring}[${key}]=\"${idle}\" ";
		if [ ! -z "${cputotals[${key}]}" ]; then
			lasttotal=${cputotals[${key}]};
			lastidle=${cpuidles[${key}]};
			haslast=1;
		fi;
	fi;
	if [ $haslast -eq 1 ]; then
		cputotal=$(echo "${total} - ${lasttotal}" |bc -l);
		cpuidle=$(echo "${idle} - ${lastidle}" |bc -l);
		if [ $cputotal -eq 0 ]; then
			usage=0;
		else
			usage="$(echo "100 - (${cpuidle} / ${cputotal} * 100)" | bc -l)";
		fi;
		if [ "$debug" = "1" ]; then
			echo "	Got CPU Total ${cputotal} Idle ${cpuidle}, Current Total ${total} Idle ${idle}, Last Total ${lasttotal} Idle ${lastidle}";
		fi;
		usage="$(echo "scale=2; ${usage}/1" | bc -l)";
		if [ "${usage:0:1}" = "." ]; then
			usage="0${usage}";
		fi;
		if [ "${prev}" != "${vzid}" ]; then
			if [ "${prev}" != "" ]; then
				if [ "$out" = "json" ]; then
					echo -n "},";
				elif [ "$out" = "serialize" ]; then
					output="${output}${coreidx}:{${coreout}}";				
					vzcount=$(($vzcount + 1));
					coreout="";
				else
					echo "";
				fi;
			fi;
			if [ "$out" = "json" ]; then
				echo -n "\"${vzid}\":{";
			elif [ "$out" = "serialize" ]; then
				output="${output}i:${vzid};a:";
				coreidx=0;
			else
				echo -n "$vzid";
			fi;
		fi;
		prev="${vzid}";
		if [ "$out" = "json" ]; then
			if [ "${cpu}" != "cpu" ]; then
				echo -n ",";
			fi;
			echo -n "\"${cpu}\":\"${usage}\"";
		elif [ "$out" = "serialize" ]; then
			coreout="${coreout}s:${#cpu}:\"${cpu}\";s:${#usage}:\"${usage}\";";
		else
			echo -n " $cpu ${usage}";
		fi;
		#echo "$vzid $cpu ${usage}%";
	fi;
done
if [ "$out" = "json" ]; then
	echo "}}";
elif [ "$out" = "serialize" ]; then
# i:0;a:9:{s:3:"cpu";s:4:"7.87";s:4:"cpu0";s:4:"7.69";s:4:"cpu1";s:5:"12.57";s:4:"cpu2";s:5:"15.64";s:4:"cpu3";s:4:"9.17";s:4:"cpu4";s:4:"4.65";s:4:"cpu5";s:5:"10.03";s:4:"cpu6";s:1:"0";s:4:"cpu7";s:4:"3.44";}
					output="${output}${coreidx}:{${coreout}}";				
					vzcount=$(($vzcount + 1));
					output="a:${vzcount}:{${output}}"
					echo "${output}";
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
