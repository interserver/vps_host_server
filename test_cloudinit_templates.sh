#!/usr/bin/env bash
# =============================================================================
# test_cloudinit_templates.sh
#
# End-to-end tester for the cloud-init "one-click app" templates in ./cloudinit.
# For each template, one at a time, it:
#   1. syncs the yaml into /vz/templates/cloudinit/  (where provirted reads it)
#   2. stop -f / destroy any prior VPS using the test vzid
#   3. provirted.phar create ... cloud-init:<base>.qcow2:<template>.yaml ...
#      (mirrors exactly what a VPS reinstall sends to the host)
#   4. waits for the guest to boot and become SSH-reachable
#   5. logs in and runs `cloud-init status --wait` (waits for the app install)
#   6. runs cloudinit_remote_check.sh inside the guest -> per-app JSON verdict
#   7. records the result, then (optionally) destroys the VPS
#
# Results are written to:  ./cloudinit_test_results/<timestamp>/results.json
# plus a human summary.txt and one create/console log per template.
#
# Per-template expectations (services/ports/files/http/commands) live in
# ./cloudinit_tests.json and are auto-derived from the yamls (edit to tune).
#
# Usage:
#   ./test_cloudinit_templates.sh                 # test every template in the registry
#   ./test_cloudinit_templates.sh openclaw wordpress lamp
#   ./test_cloudinit_templates.sh cloudinit/openclaw.yaml
#
# Config: edit the block below, or drop overrides in ~/.provirted/cloudinit_test.env
# =============================================================================
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# -----------------------------------------------------------------------------
# CONFIG (override any of these in ~/.provirted/cloudinit_test.env)
# -----------------------------------------------------------------------------
PROVIRTED="${PROVIRTED:-$SCRIPT_DIR/provirted.phar}"
CLOUDINIT_SRC="${CLOUDINIT_SRC:-$SCRIPT_DIR/cloudinit}"          # repo copy of the yamls
CLOUDINIT_DEST="${CLOUDINIT_DEST:-/vz/templates/cloudinit}"      # where provirted looks
REGISTRY="${REGISTRY:-$SCRIPT_DIR/cloudinit_tests.json}"
REMOTE_CHECK="${REMOTE_CHECK:-$SCRIPT_DIR/cloudinit_remote_check.sh}"
RESULTS_ROOT="${RESULTS_ROOT:-$SCRIPT_DIR/cloudinit_test_results}"

# --- the test VPS identity / resources (a throwaway slot you control) --------
TEST_VZID="${TEST_VZID:-vps999999}"
TEST_HOSTNAME="${TEST_HOSTNAME:-citest.trouble-free.net}"
TEST_IP="${TEST_IP:-}"                  # REQUIRED: a routable test IP on this host
TEST_IPV6_IP="${TEST_IPV6_IP:-}"        # optional
TEST_IPV6_RANGE="${TEST_IPV6_RANGE:-}"  # optional
CLIENT_IP="${CLIENT_IP:-}"              # optional, for VNC ACL
ORDER_ID="${ORDER_ID:-999999}"
ROOT_PASSWORD="${ROOT_PASSWORD:-CiTest=Passw0rd!}"
HD="${HD:-40}"
RAM="${RAM:-4096}"
CPU="${CPU:-2}"
DEFAULT_BASE="${DEFAULT_BASE:-}"        # falls back to registry defaults.base_image

# --- SSH: key auth preferred; falls back to password (needs sshpass) ---------
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_ed25519}"          # private key; .pub is injected
SSH_USER="${SSH_USER:-root}"

# --- timeouts (seconds) ------------------------------------------------------
BOOT_TIMEOUT="${BOOT_TIMEOUT:-600}"      # wait for ssh to come up
CLOUDINIT_TIMEOUT="${CLOUDINIT_TIMEOUT:-2400}"  # wait for cloud-init to finish (40m)
SSH_CONNECT_TIMEOUT="${SSH_CONNECT_TIMEOUT:-10}"

# --- behaviour ---------------------------------------------------------------
SYNC_TEMPLATES="${SYNC_TEMPLATES:-1}"    # copy yaml into CLOUDINIT_DEST before create
DESTROY_AFTER="${DESTROY_AFTER:-1}"      # destroy the VPS when done with a template
KEEP_ON_FAIL="${KEEP_ON_FAIL:-1}"        # but keep it around if the test failed

[ -f "$HOME/.provirted/cloudinit_test.env" ] && . "$HOME/.provirted/cloudinit_test.env"

# -----------------------------------------------------------------------------
log()  { printf '\033[1;36m[%s]\033[0m %s\n' "$(date +%H:%M:%S)" "$*"; }
warn() { printf '\033[1;33m[%s] WARN:\033[0m %s\n' "$(date +%H:%M:%S)" "$*" >&2; }
err()  { printf '\033[1;31m[%s] ERROR:\033[0m %s\n' "$(date +%H:%M:%S)" "$*" >&2; }

command -v python3 >/dev/null 2>&1 || { err "python3 is required"; exit 1; }
[ -f "$REGISTRY" ]     || { err "registry not found: $REGISTRY"; exit 1; }
[ -x "$PROVIRTED" ] || [ -f "$PROVIRTED" ] || { err "provirted not found: $PROVIRTED"; exit 1; }
[ -f "$REMOTE_CHECK" ] || { err "remote check not found: $REMOTE_CHECK"; exit 1; }
if [ -z "$TEST_IP" ]; then
  err "TEST_IP is not set. Set it in ~/.provirted/cloudinit_test.env (a routable test IP on this host)."
  exit 1
fi

# default base image from registry if not overridden
if [ -z "$DEFAULT_BASE" ]; then
  DEFAULT_BASE="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["defaults"]["base_image"])' "$REGISTRY" 2>/dev/null)"
  [ -z "$DEFAULT_BASE" ] && DEFAULT_BASE="ubuntu24.qcow2"
fi

# ssh auth mode
SSH_MODE="password"
if [ -f "$SSH_KEY" ] && [ -f "${SSH_KEY}.pub" ]; then
  SSH_MODE="key"
  SSH_PUBKEY="$(cat "${SSH_KEY}.pub")"
elif command -v sshpass >/dev/null 2>&1; then
  SSH_MODE="password"
  warn "no SSH key pair at $SSH_KEY(.pub); using password auth via sshpass"
else
  err "no SSH key pair at $SSH_KEY(.pub) and sshpass not installed; cannot log in to the guest"
  exit 1
fi
log "SSH auth mode: $SSH_MODE"

SSH_OPTS=(-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null
          -o ConnectTimeout="$SSH_CONNECT_TIMEOUT" -o LogLevel=ERROR
          -o PreferredAuthentications=publickey,password -o PubkeyAuthentication=yes)

ssh_run() { # ssh_run <remote command...>
  if [ "$SSH_MODE" = "key" ]; then
    ssh -i "$SSH_KEY" "${SSH_OPTS[@]}" "${SSH_USER}@${TEST_IP}" "$@"
  else
    sshpass -p "$ROOT_PASSWORD" ssh "${SSH_OPTS[@]}" "${SSH_USER}@${TEST_IP}" "$@"
  fi
}
scp_to() { # scp_to <localfile> <remotepath>
  if [ "$SSH_MODE" = "key" ]; then
    scp -i "$SSH_KEY" "${SSH_OPTS[@]}" "$1" "${SSH_USER}@${TEST_IP}:$2"
  else
    sshpass -p "$ROOT_PASSWORD" scp "${SSH_OPTS[@]}" "$1" "${SSH_USER}@${TEST_IP}:$2"
  fi
}

# -----------------------------------------------------------------------------
# resolve the list of templates to test
# -----------------------------------------------------------------------------
# Parse --groups N / --group K (split the work across N servers; run only group K,
# 1-based) out of the args; remaining args are explicit template names.
NGROUPS=1; GROUP=1
declare -a POSARGS=()
while [ "$#" -gt 0 ]; do
  case "$1" in
    --groups) NGROUPS="$2"; shift 2 ;;
    --groups=*) NGROUPS="${1#*=}"; shift ;;
    --group) GROUP="$2"; shift 2 ;;
    --group=*) GROUP="${1#*=}"; shift ;;
    *) POSARGS+=("$1"); shift ;;
  esac
done
case "$NGROUPS$GROUP" in *[!0-9]*) err "--groups/--group must be integers"; exit 1;; esac
[ "$NGROUPS" -ge 1 ] 2>/dev/null || { err "--groups must be >= 1"; exit 1; }
{ [ "$GROUP" -ge 1 ] && [ "$GROUP" -le "$NGROUPS" ]; } 2>/dev/null || { err "--group must be 1..$NGROUPS"; exit 1; }

declare -a ALLT=()
if [ "${#POSARGS[@]}" -gt 0 ]; then
  for a in "${POSARGS[@]}"; do a="$(basename "$a")"; ALLT+=("${a%.yaml}"); done
else
  while IFS= read -r t; do ALLT+=("$t"); done < <(
    python3 -c 'import json,sys;[print(k) for k in json.load(open(sys.argv[1]))["templates"]]' "$REGISTRY")
fi
# Round-robin slice into NGROUPS; keep only group GROUP (balances slow templates).
declare -a TEMPLATES=()
idx=0
for t in "${ALLT[@]}"; do
  if [ "$(( idx % NGROUPS + 1 ))" -eq "$GROUP" ]; then TEMPLATES+=("$t"); fi
  idx=$((idx+1))
done
[ "${#TEMPLATES[@]}" -gt 0 ] || { err "no templates to test"; exit 1; }
[ "$NGROUPS" -gt 1 ] && log "Group $GROUP/$NGROUPS: ${#TEMPLATES[@]} of ${#ALLT[@]} templates"

RUN_TS="$(date +%Y%m%d-%H%M%S)"
RUN_DIR="$RESULTS_ROOT/$RUN_TS"
mkdir -p "$RUN_DIR"
RESULTS_JSON="$RUN_DIR/results.json"
echo "[]" > "$RESULTS_JSON"
log "Run dir: $RUN_DIR"
log "Templates to test (${#TEMPLATES[@]}): ${TEMPLATES[*]}"

# -----------------------------------------------------------------------------
# helpers that append a result record (JSON) using python
# -----------------------------------------------------------------------------
append_result() { # append_result <json-file-with-one-object>
  python3 - "$RESULTS_JSON" "$1" <<'PY'
import json,sys
res=json.load(open(sys.argv[1]))
rec=json.load(open(sys.argv[2]))
res.append(rec)
json.dump(res, open(sys.argv[1],'w'), indent=2)
PY
}

extract_expect() { # extract_expect <slug> <outfile>  ; prints base_image on stdout
  python3 - "$REGISTRY" "$1" "$2" "$DEFAULT_BASE" <<'PY'
import json,sys
reg=json.load(open(sys.argv[1])); slug=sys.argv[2]; out=sys.argv[3]; deflt=sys.argv[4]
e=reg["templates"].get(slug, {})
base=e.get("base_image") or deflt
json.dump({"services":e.get("services",[]),"ports":e.get("ports",[]),
           "files":e.get("files",[]),"http":e.get("http",[]),
           "commands":e.get("commands",[])}, open(out,'w'))
print(base)
PY
}

cleanup_vps() {
  "$PROVIRTED" stop -f "$TEST_VZID"  >/dev/null 2>&1 || true
  "$PROVIRTED" destroy "$TEST_VZID"  >/dev/null 2>&1 || true
}

PASS=0; FAIL=0; ERRORS=0

# -----------------------------------------------------------------------------
# main loop
# -----------------------------------------------------------------------------
for slug in "${TEMPLATES[@]}"; do
  yaml="$slug.yaml"
  src="$CLOUDINIT_SRC/$yaml"
  tlog="$RUN_DIR/$slug.create.log"
  status="error"; reason=""; remote_json="{}"
  started="$(date -u +%FT%TZ)"
  log "==================== $slug ===================="

  if [ ! -f "$src" ]; then
    err "[$slug] yaml not found: $src"
    reason="yaml not found: $src"; ERRORS=$((ERRORS+1))
    rec="$RUN_DIR/.rec.json"
    python3 -c 'import json,sys;json.dump({"template":sys.argv[1],"status":"error","reason":sys.argv[2],"started":sys.argv[3]},open(sys.argv[4],"w"))' "$slug" "$reason" "$started" "$rec"
    append_result "$rec"; continue
  fi

  expect_file="$RUN_DIR/.$slug.expect.json"
  base_image="$(extract_expect "$slug" "$expect_file")"
  template_arg="cloud-init:${base_image}:${yaml}"
  log "[$slug] template arg: $template_arg"

  # 1. sync yaml to where provirted reads it
  if [ "$SYNC_TEMPLATES" = "1" ]; then
    mkdir -p "$CLOUDINIT_DEST" 2>/dev/null || true
    cp -f "$src" "$CLOUDINIT_DEST/$yaml" 2>/dev/null \
      && log "[$slug] synced yaml -> $CLOUDINIT_DEST/$yaml" \
      || warn "[$slug] could not copy yaml to $CLOUDINIT_DEST (need root?); relying on existing copy"
  fi

  # 2. clean any prior VPS in the test slot
  log "[$slug] cleaning prior VPS ($TEST_VZID)"
  cleanup_vps

  # 3. create
  log "[$slug] creating VPS (this can take a while)..."
  create_cmd=("$PROVIRTED" create --virt=kvm --order-id="$ORDER_ID")
  [ -n "$TEST_IPV6_IP" ]    && create_cmd+=(--ipv6-ip="$TEST_IPV6_IP")
  [ -n "$TEST_IPV6_RANGE" ] && create_cmd+=(--ipv6-range="$TEST_IPV6_RANGE")
  [ -n "$CLIENT_IP" ]       && create_cmd+=(--client-ip="$CLIENT_IP")
  create_cmd+=(--password="$ROOT_PASSWORD")
  [ "$SSH_MODE" = "key" ]   && create_cmd+=(--ssh-key="$SSH_PUBKEY")
  create_cmd+=("$TEST_VZID" "$TEST_HOSTNAME" "$TEST_IP" "$template_arg" "$HD" "$RAM" "$CPU")

  if ! "${create_cmd[@]}" >"$tlog" 2>&1; then
    err "[$slug] create failed (see $tlog)"
    reason="provirted create failed: $(tail -3 "$tlog" | tr '\n' ' ')"
    ERRORS=$((ERRORS+1)); status="error"
  else
    log "[$slug] create finished; waiting for SSH (timeout ${BOOT_TIMEOUT}s)"
    # 4. wait for ssh
    ssh_up=0; deadline=$(( $(date +%s) + BOOT_TIMEOUT ))
    while [ "$(date +%s)" -lt "$deadline" ]; do
      if ssh_run 'echo ok' >/dev/null 2>&1; then ssh_up=1; break; fi
      sleep 10
    done
    if [ "$ssh_up" != "1" ]; then
      err "[$slug] guest never became SSH-reachable within ${BOOT_TIMEOUT}s"
      # Host-side diagnostics: we cannot log in, so probe from the hypervisor to
      # distinguish not-booted / no-network / sshd-down / auth-rejected.
      pingable="no"; port22="closed/filtered"
      ping -c2 -W2 "$TEST_IP" >/dev/null 2>&1 && pingable="yes"
      (timeout 5 bash -c "exec 3<>/dev/tcp/$TEST_IP/22" 2>/dev/null) && port22="OPEN"
      {
        echo "===== SSH-timeout host-side diagnostics ====="
        echo "## virsh dominfo $TEST_VZID";        virsh dominfo "$TEST_VZID" 2>&1
        echo "## virsh domifaddr (lease)";         virsh domifaddr "$TEST_VZID" 2>&1
        echo "## virsh domifaddr (arp)";           virsh domifaddr "$TEST_VZID" --source arp 2>&1
        echo "## ping $TEST_IP -> $pingable";       ping -c2 -W2 "$TEST_IP" 2>&1
        echo "## tcp/22 -> $port22"
      } >> "$tlog" 2>&1
      # one-line interpretation
      if [ "$port22" = "OPEN" ]; then
        reason="ssh port 22 OPEN but login failed in ${BOOT_TIMEOUT}s — likely root key/password not injected (auth), not network"
      elif [ "$pingable" = "yes" ]; then
        reason="guest pingable but port 22 closed in ${BOOT_TIMEOUT}s — sshd not running (boot/cloud-init/base-image issue)"
      else
        reason="guest not pingable in ${BOOT_TIMEOUT}s — no network / did not boot (base-image or network-config issue)"
      fi
      warn "[$slug] $reason  (diagnostics in $(basename "$tlog"))"
      ERRORS=$((ERRORS+1)); status="error"
    else
      log "[$slug] SSH up; waiting for cloud-init to reach a terminal state (timeout ${CLOUDINIT_TIMEOUT}s)"
      # 5. wait for cloud-init — poll for a TERMINAL status instead of trusting
      #    `status --wait`, which returns instantly (non-zero) when cloud-init is
      #    absent/disabled/not-run and would make us check a half-booted guest.
      ci_state=""
      if ! ssh_run 'command -v cloud-init >/dev/null 2>&1'; then
        warn "[$slug] cloud-init is NOT installed in the guest base image — the NoCloud seed cannot run"
        ci_state="absent"
      else
        ci_empty=0; ci_deadline=$(( $(date +%s) + CLOUDINIT_TIMEOUT ))
        while [ "$(date +%s)" -lt "$ci_deadline" ]; do
          ci_state="$(ssh_run "cloud-init status 2>/dev/null | sed -n 's/^[[:space:]]*status:[[:space:]]*//p' | head -1" 2>/dev/null | tr -d '\r\n')"
          case "$ci_state" in
            done|error|degraded) break ;;
            disabled)            warn "[$slug] cloud-init reports 'disabled'"; break ;;
            running)             ci_empty=0 ;;                       # still working, keep waiting
            *)  ci_empty=$((ci_empty+1))                             # unknown/empty/not-run
                [ "$ci_empty" -ge 12 ] && { warn "[$slug] cloud-init stuck in '${ci_state:-unknown}' for ~3m"; break; } ;;
          esac
          sleep 15
        done
      fi
      log "[$slug] cloud-init state: ${ci_state:-unknown}"
      # On any non-'done' outcome, capture diagnostics so the root cause is in
      # the log rather than inferred from cascading check failures.
      if [ "$ci_state" != "done" ]; then
        {
          echo "===== cloud-init diagnostics (state=${ci_state:-unknown}) ====="
          ssh_run 'echo "## cloud-init --version"; cloud-init --version 2>&1;
                   echo; echo "## cloud-init status --long"; cloud-init status --long 2>&1;
                   echo; echo "## failed units"; systemctl --failed --no-legend 2>&1;
                   echo; echo "## /var/lib/cloud"; ls -la /var/lib/cloud /var/lib/cloud/instance 2>&1;
                   echo; echo "## tail cloud-init.log"; tail -n 40 /var/log/cloud-init.log 2>&1;
                   echo; echo "## tail cloud-init-output.log"; tail -n 40 /var/log/cloud-init-output.log 2>&1' 2>&1
        } >> "$tlog"
        warn "[$slug] cloud-init did not reach 'done' (state=${ci_state:-unknown}); diagnostics in $(basename "$tlog")"
      fi

      # 6. push + run the remote checker
      scp_to "$REMOTE_CHECK" "/tmp/cloudinit_remote_check.sh" >/dev/null 2>&1
      scp_to "$expect_file"  "/tmp/cloudinit_expect.json"     >/dev/null 2>&1
      remote_json="$(ssh_run 'bash /tmp/cloudinit_remote_check.sh /tmp/cloudinit_expect.json' 2>>"$tlog")"
      if [ -z "$remote_json" ]; then
        err "[$slug] remote check produced no output"
        reason="remote check produced no output"; status="error"; ERRORS=$((ERRORS+1))
        remote_json="{}"
      else
        echo "$remote_json" > "$RUN_DIR/$slug.checks.json"
        # ---- print EXACTLY what passed/failed and why, right after the test ----
        printf '%s' "$remote_json" | python3 -c '
import json,sys
sl=sys.argv[1]
raw=sys.stdin.read()
try:
    d=json.loads(raw)
except Exception as e:
    print("  (could not parse remote JSON: %s)" % e)
    print("  raw: "+raw[:500])
    sys.exit(0)
G=chr(27)+"[1;32m"; R=chr(27)+"[1;31m"; Y=chr(27)+"[1;33m"; D=chr(27)+"[0;90m"; N=chr(27)+"[0m"
print("  "+D+"cloud-init: "+str(d.get("cloud_init_status"))+"  errors: "+str(d.get("cloud_init_errors"))+N)
checks=d.get("checks",[])
fails=[c for c in checks if not c.get("ok") and not c.get("advisory")]
advs =[c for c in checks if not c.get("ok") and c.get("advisory")]
oks  =[c for c in checks if c.get("ok")]
for c in oks:   print("  "+G+"PASS"+N+" "+c["name"]+"  "+D+c.get("detail","")[:90]+N)
for c in advs:  print("  "+Y+"WARN"+N+" "+c["name"]+"  "+c.get("detail","")[:140]+"  "+D+"(advisory)"+N)
for c in fails: print("  "+R+"FAIL"+N+" "+c["name"]+"  ::  "+c.get("detail","")[:180])
print("  ----> "+sl+": "+str(len(oks))+" passed, "+str(len(fails))+" failed, "+str(len(advs))+" advisory")
' "$slug"
        if printf '%s' "$remote_json" | python3 -c 'import json,sys;d=json.load(sys.stdin);sys.exit(0 if d.get("overall_pass") else 1)' 2>/dev/null; then
          status="pass"; PASS=$((PASS+1)); log "[$slug] PASS"
        else
          status="fail"; FAIL=$((FAIL+1)); warn "[$slug] FAIL (see $slug.checks.json)"
          reason="$(printf '%s' "$remote_json" | python3 -c 'import json,sys;d=json.load(sys.stdin);print("; ".join(c["name"]+":"+c.get("detail","") for c in d.get("checks",[]) if not c.get("ok") and not c.get("advisory")))' 2>/dev/null)"
        fi
      fi
    fi
  fi

  # record result
  finished="$(date -u +%FT%TZ)"
  rec="$RUN_DIR/.rec.json"
  python3 - "$rec" "$slug" "$status" "$reason" "$template_arg" "$started" "$finished" "$tlog" "$remote_json" <<'PY'
import json,sys
out=sys.argv[1]
rec={"template":sys.argv[2],"status":sys.argv[3],"reason":sys.argv[4],
     "template_arg":sys.argv[5],"started":sys.argv[6],"finished":sys.argv[7],
     "create_log":sys.argv[8]}
try: rec["checks"]=json.loads(sys.argv[9])
except Exception: rec["checks"]={}
json.dump(rec,open(out,'w'))
PY
  append_result "$rec"

  # 7. teardown
  if [ "$DESTROY_AFTER" = "1" ]; then
    if [ "$status" != "pass" ] && [ "$KEEP_ON_FAIL" = "1" ]; then
      warn "[$slug] keeping VPS $TEST_VZID for inspection (status=$status)"
      warn "[$slug] destroy it later with: $PROVIRTED stop -f $TEST_VZID; $PROVIRTED destroy $TEST_VZID"
      # leave it; subsequent template will clean it before its own create
    else
      log "[$slug] destroying test VPS"
      cleanup_vps
    fi
  fi
done

# -----------------------------------------------------------------------------
# summary
# -----------------------------------------------------------------------------
total=${#TEMPLATES[@]}
summary="$RUN_DIR/summary.txt"
{
  echo "cloud-init template test run: $RUN_TS"
  echo "templates tested: $total   pass: $PASS   fail: $FAIL   error: $ERRORS"
  echo
  python3 - "$RESULTS_JSON" <<'PY'
import json,sys
for r in json.load(open(sys.argv[1])):
    line=f"{r['status'].upper():6}  {r['template']}"
    if r['status']!='pass' and r.get('reason'):
        line+="  -- "+r['reason'][:160]
    print(line)
PY
} | tee "$summary"

log "Results JSON: $RESULTS_JSON"
log "Summary:      $summary"
[ "$FAIL" -eq 0 ] && [ "$ERRORS" -eq 0 ] && exit 0 || exit 1
