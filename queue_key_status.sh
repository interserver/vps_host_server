#!/bin/bash
# ---------------------------------------------------------------------------
# queue_key_status.sh — read-only triage for a host stuck on the legacy
# plaintext queue path.
#
# Run this when the panel logs
#   queue-crypt master=vps:NNN key=<fp> action=... quadrant=f sealed=0
#   outcome=legacy_plaintext warn=plaintext-from-keyed
# for this host. That WARN means the panel HOLDS a key for this master but the
# request arrived unsealed, and there are only three reasons for it. The key
# fingerprint printed below uses the same first6…last4 form as the panel log,
# so it can be compared directly against the key= field.
#
# Changes nothing. Prints a verdict and the remediation.
# ---------------------------------------------------------------------------
base="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
KEYFILE="${QUEUE_KEY_FILE:-/etc/myadmin/queue.key}"
RETRY_COOLDOWN=21600

if [ -n "${PANEL_CRYPT:-}" ]; then
	CRYPT=($PANEL_CRYPT)
elif [ -x /usr/local/bin/panel-crypt ]; then
	CRYPT=(/usr/local/bin/panel-crypt)
elif [ -x "$base/queue-crypto.php" ]; then
	CRYPT=("$base/queue-crypto.php")
elif [ -r "$base/queue-crypto.php" ] && command -v php >/dev/null 2>&1; then
	CRYPT=(php "$base/queue-crypto.php")
else
	CRYPT=()
fi

echo "host:      $(hostname)"
echo "crypt cli: ${CRYPT[*]:-<none found>}"

capable=0
if [ ${#CRYPT[@]} -gt 0 ] && "${CRYPT[@]}" --selftest >/dev/null 2>&1; then
	capable=1
	echo "selftest:  ok"
else
	echo "selftest:  FAIL"
	if command -v php >/dev/null 2>&1; then
		php -r 'printf("           sodium=%s aes-256-gcm=%s php=%s\n", function_exists("sodium_crypto_generichash")?"yes":"NO", in_array("aes-256-gcm", array_map("strtolower", openssl_get_cipher_methods()), true)?"yes":"NO", PHP_VERSION);'
	fi
fi

echo "keyfile:   $KEYFILE"
if [ -f "$KEYFILE" ]; then
	key="$(tr -d ' \t\r\n' <"$KEYFILE")"
	if [ ${#key} -eq 64 ] && [ -z "$(printf '%s' "$key" | tr -d '0-9a-f')" ]; then
		printf '           present, fingerprint %s…%s (compare with the panel log key= field)\n' "${key:0:6}" "${key:60:4}"
		keystate=ok
	else
		echo "           PRESENT BUT CORRUPT (${#key} chars, expected 64 lowercase hex)"
		keystate=corrupt
	fi
	ls -l "$KEYFILE" | sed 's/^/           /'
else
	echo "           ABSENT"
	keystate=absent
fi

if [ -f "$KEYFILE.keydrift" ]; then
	echo "keydrift:  SET ($(date -r "$KEYFILE.keydrift" '+%Y-%m-%d %H:%M:%S')) — the panel refused this host's"
	echo "           plaintext update_key, i.e. it holds a DIFFERENT key. Needs the panel-side reset below."
	drift=1
else
	drift=0
fi

if [ -f "$KEYFILE.suspect" ]; then
	echo "suspect:   SET ($(date -r "$KEYFILE.suspect" '+%Y-%m-%d %H:%M:%S'))"
	suspect=1
else
	echo "suspect:   clear"
	suspect=0
fi

cooldown=elapsed
if [ -e "$KEYFILE.retry" ]; then
	age=$(( $(date +%s) - $(stat -c %Y "$KEYFILE.retry") ))
	if [ $age -lt $RETRY_COOLDOWN ]; then
		cooldown=blocked
		echo "retry:     stamped ${age}s ago — re-enrolment BLOCKED for another $(( (RETRY_COOLDOWN - age) / 60 )) min"
	else
		echo "retry:     stamped ${age}s ago — cooldown elapsed, next request may re-enrol"
	fi
else
	echo "retry:     none — next request may re-enrol"
fi

echo
cat <<'NOTE'

panel-side reset (needed whenever the keys have drifted):
  Admin UI: Host Server view -> "Revoke Queue Key" (QueueKeyAdmin::revoke,
  NULLs both columns and stamps history), or by hand:

    -- vps master vps:NNN
    UPDATE vps_masters SET vps_queue_key=NULL, vps_queue_key_updated=NULL WHERE vps_id=NNN;
    -- quickservers master qs:NNN  <-- table/column prefix is qs_, NOT quickservers_
    UPDATE qs_masters  SET qs_queue_key=NULL,  qs_queue_key_updated=NULL  WHERE qs_id=NNN;

  The module is 'quickservers' but settings['PREFIX'] is 'qs' (config.inc.php:275),
  so a qs master lives in qs_masters. NULLing quickservers_* changes nothing and
  is the usual reason a reset "does not take".
NOTE

echo "verdict:"
if [ "$drift" -eq 1 ]; then
	echo "  Key drift confirmed by provirted: the panel holds a different key for this"
	echo "  master and refused the plaintext update_key (UpdateKey.php step 5), sealing"
	echo "  the refusal with the key we do not have. No host-side retry can fix this."
	echo "  Fix panel-side: NULL <prefix>_queue_key for this master, then the next cron"
	echo "  run bootstraps cleanly and clears this marker."
elif [ "$capable" -ne 1 ]; then
	echo "  No usable crypto on this host — permanent legacy plaintext by design."
	echo "  Install the sodium extension (php-sodium / php8.x-sodium) and confirm"
	echo "  openssl exposes aes-256-gcm, then re-run."
elif [ "$keystate" = absent ] || [ "$keystate" = corrupt ]; then
	echo "  Crypto works but this host has no usable key while the panel holds one."
	echo "  A plaintext update_key CANNOT re-key an already-keyed master (UpdateKey.php"
	echo "  step 5 refuses it, and seals the refusal with the key we do not have), so"
	echo "  this host can never re-enrol on its own."
	echo "  Fix panel-side: NULL <prefix>_queue_key for this master, then the next cron"
	echo "  run bootstraps cleanly."
	[ "$keystate" = corrupt ] && echo "  Also remove the corrupt $KEYFILE by hand — nothing overwrites it automatically."
elif [ "$suspect" -eq 1 ]; then
	echo "  Key present but this host is suspect-flagged, so it deliberately sends"
	echo "  plaintext until a re-enrolment verifies."
	if [ "$cooldown" = blocked ]; then
		echo "  The 6h retry cooldown is still blocking that attempt. To retry now:"
		echo "    rm -f $KEYFILE.retry"
	fi
	echo "  If the fingerprint above MATCHES the panel's key= field, the next attempt"
	echo "  succeeds (UpdateKey.php step 4 same-key idempotence) and clears the flag."
	echo "  If it does NOT match, the keys have drifted: NULL <prefix>_queue_key for"
	echo "  this master panel-side, since plaintext re-keying is refused."
else
	echo "  Key present, not suspect, crypto works: this host SHOULD be sealing."
	echo "  If the panel still logs warn=plaintext-from-keyed for it, compare the"
	echo "  fingerprint above with the panel's key= field — a mismatch means drift"
	echo "  and needs the panel-side key reset. Also confirm provirted.phar here is"
	echo "  the deployed build: $base/provirted.phar"
	[ -f "$base/provirted.phar" ] && md5sum "$base/provirted.phar" | sed 's/^/    /'
fi
exit 0
