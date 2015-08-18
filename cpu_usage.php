#!/usr/bin/php
<?php
/**
# CPU Usage Totals Per VPS Per CPU/Core
* Written by Joe Huss <detain@interserver.net>
* - This is just way better than anything else out there for gathering this info
*
* http://forum.interserver.net/forum/threads/script-get-openvz-cpu-usage-totals-per-vps-per-cpu-core.4859/
*
* Arguments:
*	-json		outputs in JSON for easy use in many languages
*	-serialize	outputs in PHP serialize() format, easy to unserialize()
*
* There are a few variables here you can customize:
* 	debug		set to 1 to enabe debugging, 0 disables
* 	bashtest	set to 1 to enable bash based math tests, compairing the results to bc math
* 	me 		email address to notify of a problem
*/
// -=[ Begin Configuration ]
$debug=0;
$bashtest=0;
$me='detain@interserver.net';
// -=[ End Configuration ]----------
if ($_SERVER['argc'] > 0)
	if ($_SERVER['argv'][1] == '-serialize')
		$out = 'serialize';
	elseif ($_SERVER['argv'][1] == '-json')
		$out = 'json';
	else
		$out = 'normal';
else
	$out = 'normal';
$cpu_proc_mask = '/proc/vz/fairsched/*/cpu.proc.stat';
$files = trim(`ls $cpu_proc_mask;`);
if ($files == '/proc/vz/fairsched/*/cpu.proc.stat')
	echo "Error, /proc/vz/fairsched/*/cpu.proc.stat entries do not exist!
	Most likely cause is, this is either not an OpenVZ server, 
	or not booted into the proper kernel.\n";
else
{
	if (file_exists('~/.cpu_usage.last.ser'))
	{
		$usage = unserialize(trim(file_get_contents('~/.cpu_usage.serial')));
	}
/*
	if [ -e ~/.cpu_usage.last.sh ]; then
		source ~/.cpu_usage.last.sh;
	elif [ ${BASH_VERSION:0:1} -ge 4 ]; then
		declare -A cputotals;
		declare -A cpuidles;
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
	for i in $(grep "^cpu" $cpu_proc_mask | tr / " "  | tr : " " | awk '{ print $4 " " $6 " " $7 " " $8 " " $9 " " $10 " " $11 " " $12 " " $13 " " $14 }'); do
		vzid="$(echo "$i" | awk '{ print $1 }')";
		cpu="$(echo "$i" | awk '{ print $2 }')";
		total="$(echo "$i" | awk '{ print $3 "+" $4 "+" $5 "+" $6 "+" $7 "+" $8 "+" $9}' | bc -l)";
		if [ ${bashtest} -eq 1 ]; then
			total_bash="$(($(echo "$i" | awk '{ print $3 "+" $4 "+" $5 "+" $6 "+" $7 "+" $8 "+" $9}')))";
			if [ "${total_bash}" != "${total}" ]; then
				s="Difference between Bash Math and BC Math found (Bash ${total_bash} != BC ${total})";
				echo "$s" | mail -s "$s" ${me} >/dev/null 2>&1
				echo "$s" >&2;
			fi;
		fi;
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
			cputotal=$(echo "${total} - ${lasttotal}" | bc -l);
			if [ ${bashtest} -eq 1 ]; then
				cputotal_bash=$((${total} - ${lasttotal}));
				if [ "${cputotal_bash}" != "${cputotal}" ]; then
					s="Difference between Bash Math and BC Math found (Bash ${cputotal_bash} != BC ${cputotal})";
					echo "$s" | mail -s "$s" ${me} >/dev/null 2>&1
					echo "$s" >&2;
				fi;
			fi;
			cpuidle=$(echo "${idle} - ${lastidle}" | bc -l);
			if [ ${bashtest} -eq 1 ]; then
				cpuidle_bash=$((${idle} - ${lastidle}));
				if [ "${cpuidle_bash}" != "${cpuidle}" ]; then
					s="Difference between Bash Math and BC Math found (Bash ${cpuidle_bash} != BC ${cpuidle})";
					echo "$s" | mail -s "$s" ${me} >/dev/null 2>&1
					echo "$s" >&2;
				fi;
			fi;
			if [ $cputotal -eq 0 ]; then
				usage=0;
			else
				usage="$(echo "100 - (100 * ${cpuidle} / ${cputotal})" | bc -l)";
				if [ ${bashtest} -eq 1 ]; then
					usage_bash=$((100 - (100 * ${cpuidle} / ${cputotal})));
					if [ "${usage_bash}" != "${usage}" ]; then
						s="Difference between Bash Math and BC Math found (Bash ${usage_bash} != BC ${usage})";
						echo "$s" | mail -s "$s" ${me} >/dev/null 2>&1
						echo "$s" >&2;
					fi;
				fi;
			fi;
			if [ "$debug" = "1" ]; then
				echo "	Got CPU Total ${cputotal} Idle ${cpuidle}, Current Total ${total} Idle ${idle}, Last Total ${lasttotal} Idle ${lastidle}";
			fi;
			# Bash 2 Decimal point Calculation, want 4.00, deal with number * 100, so a=400
			# 4.00 = ${a:0:$((${#a} - 2))}.${a:$((${#a} - 2))}
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
				coreidx=$(($coreidx + 1));
			else
				echo -n " $cpu ${usage}";
			fi;
			#echo "$vzid $cpu ${usage}%";
		fi;
	done
	if [ "$out" = "json" ]; then
		echo "}}";
	elif [ "$out" = "serialize" ]; then
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
*/
}
