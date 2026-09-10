#!/bin/bash
set -o pipefail
export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/bin:/usr/bin:/sbin:/usr/sbin"
export base="$(readlink -f "$(dirname "$0")")"
export url=https://myvps.interserver.net/qs_queue.php
export dir=${base}
export log=$dir/cron.output
export old_cron=1
# Queue envelope crypto (plan step 5.2). All of it — capability probe,
# create-if-absent genkey, sealed bootstrap, suspect/6h-retry cooldown and
# the queue_request choke-point — lives in queue_lib.sh so this script, the
# other host scripts and the provirted phar share one implementation. The
# lib reads $url/$log/$dir from here and resolves the crypto CLI itself
# (PANEL_CRYPT override, /usr/local/bin/panel-crypt, else the
# queue-crypto.php shipped in this directory).
if [ -r "$base/queue_lib.sh" ]; then
	. "$base/queue_lib.sh"
else
	# Partial deploy: no crypto, and say so instead of silently curling.
	crypto_capable=0
	function cron_warn() { echo "[$(date "+%Y-%m-%d %H:%M:%S")] WARN $1" >>$log; }
	function queue_crypto_startup() { cron_warn "queue_lib.sh missing from $base — queue traffic stays legacy plaintext"; }
	function queue_request() { local a="$1"; shift; local e="$1"; shift; curl -s "$@" -d "action=$a" "$e" 2>/dev/null; }
fi

function age() {
	local filename=$1
	local changed=$(stat -c %Y "$filename")
	local now=$(date +%s)
	local elapsed
	let elapsed=now-changed
	echo $elapsed
}


if [ "$SHELL" = "/bin/sh" ] && [ -e /cron.vps.disabled ]; then
	exit
fi
if [ -f /dev/shm/lock ]; then
	exit
fi
# .enable_workerman bypass RETIRED (plan 5.2, user directive 2026-09-08): the
# sealed cron path now always runs; the workerman/ directory is left untouched
# for a separate purge by its owner. old_cron stays 1 unconditionally.
if [ $old_cron -eq 1 ]; then
	export pslog=$dir/cron.psoutput
	ps ux | grep "/bin/bash $0" | grep -v -e grep -e " $(($$ + 1)) " >$pslog
	count=$(cat $pslog | wc -l)
	if [ $count -ge 2 ]; then
		echo "Got count $count" >>$log
		cat $pslog >>$log
		# kill a get list older than 2 hours
		if [ $(age .cron.age) -gt 7200 ]; then
			if [ "$(ps uax | grep qs_get_list | grep -v grep)" != "" ]; then
				kill -9 $(ps uax | grep qs_get_list | grep -v grep | awk '{ print $2 }')
			fi
		fi
	else
		rm -f cron.age
		touch .cron.age
		echo "[$(date "+%Y-%m-%d %H:%M:%S")] Crontab Startup" >>$log
		# STARTUP crypto block (plan 5.2): capability gate first — no usable
		# crypto CLI means permanent legacy, no crypto code path runs. With
		# capability present and no key file yet, this enrols once
		# (create-if-absent genkey + plaintext update_key) against $url, the
		# module endpoint whose master the panel keys.
		queue_crypto_startup "$url"
		if [ -e /proc/vz ]; then
			#$dir/cpu_usage_updater.sh 2>$dir/cron.cpu_usage >&2 &
			$dir/provirted.phar cron cpu-usage 2>$dir/cron.cpu_usage >&2 &
		fi
		#$dir/qs_update_info.php >> $log 2>&1
		$dir/provirted.phar cron host-info -a >>$log 2>&1
		#curl -s --connect-timeout 60 --max-time 600 -k -d action=get_new_qs $url 2>/dev/null > $dir/cron.cmd;
		queue_request get_new_qs http://myvps.interserver.net:55151/queue.php --connect-timeout 60 --max-time 600 -k >$dir/cron.cmd
		if [ "$(cat $dir/cron.cmd)" != "" ] && [ "$(grep "Session halted." $dir/cron.cmd)" = "" ]; then
			echo "Get New VPS Running:    $(cat $dir/cron.cmd)" >>$log
			. $dir/cron.cmd >>$log 2>&1
		elif [ "$(grep "Session halted." $dir/cron.cmd)" != "" ]; then
			cron_warn "Session halted. in get_new_qs response (post-decrypt) — not executing"
		fi
		if [ ! -e /root/_disableqstraffic ]; then
			#$dir/qs_traffic.php >> $log 2>&1
			$dir/provirted.phar cron bw-info -a >>$log 2>&1
		fi
		#$dir/vps_traffic_new.php quickservers >> $log 2>&1
		queue_request map http://myvps.interserver.net:55151/queue.php --connect-timeout 10 --max-time 15 | bash
		#curl -s --connect-timeout 60 --max-time 600 -k -d action=get_queue $url 2>/dev/null > $dir/cron.cmd;
		queue_request get_qs_queue http://myvps.interserver.net:55151/queue.php --connect-timeout 60 --max-time 600 -k >$dir/cron.cmd
		if [ "$(cat $dir/cron.cmd)" != "" ] && [ "$(grep "Session halted." $dir/cron.cmd)" = "" ]; then
			echo "Get Queue Running:    $(cat $dir/cron.cmd)" >>$log
			. $dir/cron.cmd >>$log 2>&1
		elif [ "$(grep "Session halted." $dir/cron.cmd)" != "" ]; then
			cron_warn "Session halted. in get_qs_queue response (post-decrypt) — not executing"
		fi
		#$dir/qs_get_list.php >> $log 2>&1
		$dir/provirted.phar cron vps-info -a >>$log 2>&1
		#        if [ ! -e .cron_daily.age ] || [ $(age .cron_daily.age) -ge 86400 ]; then
		#            if [ "$(ps uax|grep -e update_virtuozzo -e qs_cron_daily|grep -v grep)" = "" ]; then
		#                touch .cron_daily.age
		#                php qs_cron_daily.php >> cron.output 2>&1
		#            fi
		#        fi
		/bin/rm -f $dir/cron.cmd
	fi
	rm -f $pslog
fi
