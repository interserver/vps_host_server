#!/bin/bash
# ---------------------------------------------------------------------------
# queue_lib.sh — THE shell-side chokepoint for every queue POST this host
# makes (plan step 5.2). Source it, then use queue_request; never curl a
# queue endpoint directly (the provirted phar has the same rule for PHP —
# App\QueueGateway::sendRequest).
#
# Extracted verbatim from the duplicated blocks in vps_cron.sh / qs_cron.sh so
# the other host scripts that used to hand-roll a plaintext curl
# (vps_swift_restore.sh, templates/install_virtuozzo.sh) go through the same
# seal/open/bootstrap logic instead of showing up on the panel as
# "outcome=legacy_plaintext warn=plaintext-from-keyed".
#
# Caller contract:
#   url  — module endpoint this host enrols against (vps_queue.php / qs_queue.php)
#   log  — optional log file for cron_warn (defaults to <lib dir>/cron.output)
#   dir  — optional scratch dir for response temp files (defaults to lib dir)
# ---------------------------------------------------------------------------

queue_lib_base="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
: "${dir:=$queue_lib_base}"
: "${log:=$queue_lib_base/cron.output}"
: "${url:=https://myvps.interserver.net/vps_queue.php}"
KEYFILE="${QUEUE_KEY_FILE:-/etc/myadmin/queue.key}"
export QUEUE_KEY_FILE="$KEYFILE"
RETRY_COOLDOWN=21600
crypto_capable=0

# Resolve the crypto CLI. The 5.1 shim ships IN THIS DIRECTORY as
# queue-crypto.php; /usr/local/bin/panel-crypt is only the optional installed
# copy. Gating solely on that install path (as both cron scripts used to) left
# every host that never got it in permanent legacy mode: no bootstrap, no
# payload, and — once anything else registered a key — a panel-side
# plaintext-from-keyed WARN on every request.
if [ -n "${PANEL_CRYPT:-}" ]; then
	# Explicit override wins and may carry its own interpreter, e.g.
	# PANEL_CRYPT="php /root/cpaneldirect/queue-crypto.php".
	PANEL_CRYPT_CMD=($PANEL_CRYPT)
elif [ -x /usr/local/bin/panel-crypt ]; then
	PANEL_CRYPT_CMD=(/usr/local/bin/panel-crypt)
elif [ -x "$queue_lib_base/queue-crypto.php" ]; then
	PANEL_CRYPT_CMD=("$queue_lib_base/queue-crypto.php")
elif [ -r "$queue_lib_base/queue-crypto.php" ] && command -v php >/dev/null 2>&1; then
	PANEL_CRYPT_CMD=(php "$queue_lib_base/queue-crypto.php")
else
	PANEL_CRYPT_CMD=()
fi

function panel_crypt() {
	[ ${#PANEL_CRYPT_CMD[@]} -gt 0 ] || return 127
	"${PANEL_CRYPT_CMD[@]}" "$@"
}

function cron_warn() {
	echo "[$(date "+%Y-%m-%d %H:%M:%S")] WARN $1" >>"$log"
}

function queue_age() {
	local filename=$1
	local changed=$(stat -c %Y "$filename" 2>/dev/null)
	[ -n "$changed" ] || { echo 0; return; }
	echo $(($(date +%s) - changed))
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
#
# $1 (optional) endpoint to enrol against; defaults to $url. The panel resolves
# the master PER ENDPOINT (vps_queue.php -> vps:NNN, qs_queue.php -> qs:NNN),
# so enrolling anywhere else leaves this module's master key=none.
function bootstrap_key() {
	local endpoint="${1:-$url}"
	local key=""
	key=$(panel_crypt genkey 2>/dev/null)
	if [ $? -ne 0 ] || [ ${#key} -ne 64 ]; then
		cron_warn "authfail: panel-crypt genkey failed — staying legacy"
		return 1
	fi
	local response=""
	response=$(curl -s --connect-timeout 60 --max-time 600 -k -d action=update_key --data-urlencode "queue_key=$key" "$endpoint" 2>/dev/null)
	case "$response" in
	Q1.*)
		printf '%s' "$response" | panel_crypt dec update_key >/dev/null 2>&1
		if [ $? -eq 0 ]; then
			rm -f "$KEYFILE.suspect" "$KEYFILE.retry"
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
	if [ -e "$KEYFILE.retry" ] && [ $(queue_age "$KEYFILE.retry") -lt $RETRY_COOLDOWN ]; then
		return 1
	fi
	touch "$KEYFILE.retry"
	bootstrap_key "$1"
}

# Capability gate + one-shot enrolment. Call once at startup: a failing (or
# absent) crypto CLI means permanent legacy, no crypto code path runs.
# $1 (optional) endpoint to enrol against; defaults to $url.
function queue_crypto_startup() {
	if panel_crypt --selftest >/dev/null 2>&1; then
		crypto_capable=1
		if [ ! -f "$KEYFILE" ]; then
			bootstrap_key "$1"
		fi
	else
		crypto_capable=0
	fi
}

# queue_request <action> <endpoint-url> [extra curl flags...]
# THE single choke-point for every queue POST. stdout carries the body the
# caller consumes: opened plaintext when the response is a Q1. envelope, the
# raw response on every legacy/serve-continuity path. Exit:
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
		payload=$(printf '%s' "$action" | panel_crypt enc "$action" 2>/dev/null)
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
		panel_crypt dec "$action" <"$response_file" >"$plaintext_file" 2>/dev/null
		local dec_rc=$?
		if [ $dec_rc -eq 0 ]; then
			cat "$plaintext_file"
			rm -f "$response_file" "$plaintext_file"
			return 0
		fi
		if [ ! -f "$KEYFILE" ]; then
			cron_warn "authfail: $action got an envelope but the key file is gone — needs bootstrap"
			maybe_cooldown_bootstrap
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
	elif [ "$crypto_capable" -eq 1 ] && [ ! -f "$KEYFILE" ]; then
		# Not enrolled at all: every request would stay plaintext forever
		# (and WARN once anything else registers a key for this master).
		cron_warn "unenrolled: $action sent plaintext with no key file — running cooldown-gated bootstrap"
		maybe_cooldown_bootstrap
	fi
	cat "$response_file" 2>/dev/null
	rm -f "$response_file" "$plaintext_file"
	return 0
}
