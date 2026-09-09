#!/bin/bash
set -o pipefail
export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/bin:/usr/bin:/sbin:/usr/sbin"
export base="$(readlink -f "$(dirname "$0")")"
export url=https://myvps.interserver.net/qs_queue.php
export dir=${base}
export log=$dir/cron.output
export old_cron=1
# Queue envelope crypto (plan step 5.2; shim = queue-crypto.php step 5.1,
# installed on hosts as /usr/local/bin/panel-crypt). PANEL_CRYPT and
# QUEUE_KEY_FILE are the sandbox seams used by 6.2/6.5 — the shim and this
# shell always agree on ONE key path via the exported QUEUE_KEY_FILE.
PANEL_CRYPT="${PANEL_CRYPT:-/usr/local/bin/panel-crypt}"
KEYFILE="${QUEUE_KEY_FILE:-/etc/myadmin/queue.key}"
export QUEUE_KEY_FILE="$KEYFILE"
RETRY_COOLDOWN=21600
crypto_capable=0

function age() {
	local filename=$1
	local changed=$(stat -c %Y "$filename")
	local now=$(date +%s)
	local elapsed
	let elapsed=now-changed
	echo $elapsed
}

# ---------------------------------------------------------------------------
# Envelope layer (plan 5.2). Panel responses execute as ROOT bash, so a Q1.
# envelope is our authentication layer: nothing that claims to be an envelope
# is ever executed unless our own key opens it (panel-crypt dec is
# all-or-nothing; exit 3 aborts before any source/pipe). Selftest failure =
# permanent legacy: no crypto path runs at all (user rule).
# Alert tags below (plaintext-from-keyed, authfail) are referenced by 6.7 ops
# docs; payload-for-unkeyed is the panel-side companion WARN.
# ---------------------------------------------------------------------------
function cron_warn() {
	echo "[$(date "+%Y-%m-%d %H:%M:%S")] WARN $1" >>$log
}

# Attach an envelope payload to requests? No while suspect-flagged: the panel
# then holds a key we do not, so we downgrade to no-payload plaintext (its
# quadrant (f) serves us + WARNs) until a verified bootstrap clears the flag.
function crypto_send_payload() {
	[ "$crypto_capable" -eq 1 ] || return 1
	[ -f "$KEYFILE" ] || return 1
	[ -f "$KEYFILE.suspect" ] && return 1
	return 0
}

# Self-bootstrap: create-if-absent genkey (co-agent convergent) + plaintext
# update_key (accepted only while the panel's stored key is NULL). The reply
# must be Q1.* AND open with our key. On mismatch keep going: the panel may
# predate UpdateKey.php (render() has no class_exists guard, so an old panel
# fatals/empties on the action); the key file is already written — next runs
# send payload and quadrant (b)/(f) absorb it.
function bootstrap_key() {
	local key=""
	key=$("$PANEL_CRYPT" genkey 2>/dev/null)
	if [ $? -ne 0 ] || [ ${#key} -ne 64 ]; then
		cron_warn "authfail: panel-crypt genkey failed — staying legacy"
		return 1
	fi
	local response=""
	response=$(curl -s --connect-timeout 60 --max-time 600 -k -d action=update_key --data-urlencode "queue_key=$key" "$url" 2>/dev/null)
	case "$response" in
	Q1.*)
		printf '%s' "$response" | "$PANEL_CRYPT" dec update_key >/dev/null 2>&1
		if [ $? -eq 0 ]; then
			rm -f "$KEYFILE.suspect"
			cron_warn "bootstrap: panel confirmed key (sealed update_key reply opened) — envelope mode active"
			return 0
		fi
		;;
	esac
	cron_warn "bootstrap: panel did not confirm key (pre-UpdateKey panel or foreign key) — key file kept, steady state converges via payload path"
	return 1
}

# Re-bootstrap at most once per 6h — $KEYFILE.retry stamp age gate prevents
# per-run key churn while the panel is unkeyed/stale.
function maybe_cooldown_bootstrap() {
	[ "$crypto_capable" -eq 1 ] || return 1
	if [ -e "$KEYFILE.retry" ] && [ $(age "$KEYFILE.retry") -lt $RETRY_COOLDOWN ]; then
		return 1
	fi
	touch "$KEYFILE.retry"
	bootstrap_key
}

# queue_request <action> <endpoint-url> [extra curl flags...]
# THE single choke-point for every queue POST in this script. stdout carries
# the body the caller executes: opened plaintext when the response is a Q1.
# envelope, the raw response on every legacy/serve-continuity path. Exit:
#   0 = body emitted (may be empty — idle queue, same as today)
#   2 = key file vanished mid-run — needs bootstrap (body suppressed)
#   3 = envelope rejected (HTTP 403 or dec exit 2/3) — FAIL-CLOSED: body
#       suppressed, suspect flag touched, cooldown-gated bootstrap retried.
function queue_request() {
	local action="$1"
	shift
	local endpoint="$1"
	shift
	local response_file="$dir/cron.resp.$$"
	local plaintext_file="$response_file.open"
	local curl_rc=0 sent_payload=0 status=""

	local curl_args=(curl -s "$@" -o "$response_file" -w '%{http_code}' -d "action=$action")
	if crypto_send_payload; then
		local payload=""
		payload=$(printf '%s' "$action" | "$PANEL_CRYPT" enc "$action" 2>/dev/null)
		if [ $? -eq 0 ] && [ -n "$payload" ]; then
			sent_payload=1
			curl_args+=(--data-urlencode "payload=$payload")
		elif [ ! -f "$KEYFILE" ]; then
			# shim exit 2 because the key file is gone == needs-bootstrap.
			cron_warn "authfail: enc failed (key file gone mid-run) — $action goes plaintext this request"
		fi
	fi
	curl_args+=("$endpoint")

	status=$("${curl_args[@]}" 2>/dev/null)
	curl_rc=$?

	if [ $curl_rc -ne 0 ] || [ $crypto_capable -ne 1 ]; then
		# transport failure or permanent-legacy host: today's shape — emit
		# whatever body arrived, infer no crypto state from it.
		cat "$response_file" 2>/dev/null
		rm -f "$response_file" "$plaintext_file"
		return 0
	fi
	if [ "$status" = "403" ]; then
		# Quadrant (e): offered envelope would not open panel-side. Do NOT
		# self-heal blindly — the panel holds a key we don't.
		touch "$KEYFILE.suspect"
		cron_warn "authfail: $action got HTTP 403 from $endpoint — panel holds a key we don't; suspect set, downgrading to no-payload plaintext"
		maybe_cooldown_bootstrap
		rm -f "$response_file" "$plaintext_file"
		return 3
	fi
	if [ "$(head -c 3 "$response_file" 2>/dev/null)" = "Q1." ]; then
		"$PANEL_CRYPT" dec "$action" <"$response_file" >"$plaintext_file" 2>/dev/null
		local dec_rc=$?
		if [ $dec_rc -eq 0 ]; then
			cat "$plaintext_file"
			rm -f "$response_file" "$plaintext_file"
			return 0
		fi
		if [ ! -f "$KEYFILE" ]; then
			cron_warn "authfail: $action got an envelope but the key file is gone — needs bootstrap"
			rm -f "$response_file" "$plaintext_file"
			return 2
		fi
		touch "$KEYFILE.suspect"
		cron_warn "authfail: $action envelope would not open with our key (dec exit $dec_rc) — suspect set, downgrading to no-payload plaintext"
		maybe_cooldown_bootstrap
		rm -f "$response_file" "$plaintext_file"
		return 3
	fi
	# Plaintext (no envelope) response.
	if [ $sent_payload -eq 1 ]; then
		# Serve-continuity (parked policy): WARN, still source/pipe the
		# plaintext exactly as today, then cooldown-gated re-bootstrap.
		cron_warn "plaintext-from-keyed: $action sent payload but $endpoint answered plaintext — serving it (parked policy), cooldown-gated re-bootstrap"
		maybe_cooldown_bootstrap
	elif [ -f "$KEYFILE.suspect" ]; then
		# Suspect downgrade: quadrant (f) serves plaintext; keep retrying the
		# bootstrap inside the 6h cooldown until it verifies.
		cron_warn "authfail: suspect-downgrade active — $endpoint answered plaintext; retrying bootstrap inside cooldown"
		maybe_cooldown_bootstrap
	fi
	cat "$response_file" 2>/dev/null
	rm -f "$response_file" "$plaintext_file"
	return 0
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
		# STARTUP crypto block (plan 5.2): capability gate first — a failing
		# (or absent) panel-crypt --selftest means permanent legacy, no crypto
		# code path runs. With capability present and no key file yet, run the
		# self-bootstrap once (create-if-absent genkey + plaintext update_key).
		if [ -x "$PANEL_CRYPT" ] && "$PANEL_CRYPT" --selftest >/dev/null 2>&1; then
			crypto_capable=1
			if [ ! -f "$KEYFILE" ]; then
				bootstrap_key
			fi
		fi
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
