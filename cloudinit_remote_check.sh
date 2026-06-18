#!/usr/bin/env bash
# =============================================================================
# cloudinit_remote_check.sh  — runs INSIDE the freshly-installed VPS.
#
# Copied to the guest by test_cloudinit_templates.sh, invoked as:
#     bash /tmp/cloudinit_remote_check.sh /tmp/cloudinit_expect.json
#
# Reads an "expectations" JSON (the per-template entry from cloudinit_tests.json)
# and prints a single JSON object on stdout describing every check + verdict.
# It NEVER exits non-zero for a failed test (so the orchestrator always gets
# JSON back); it only exits non-zero if it cannot produce JSON at all.
#
# overall_pass = every NON-advisory check passed. Advisory checks (e.g. a noisy
# scan of the bootstrap log for the word "error") are reported but do not by
# themselves fail a template.
#
# Depends only on tools in the base images: bash, grep/sed/awk, ss, systemctl,
# curl, and jq OR python3 for reading the expectations JSON.
# =============================================================================
set -uo pipefail

EXPECT_FILE="${1:-/tmp/cloudinit_expect.json}"

have_jq=0; command -v jq      >/dev/null 2>&1 && have_jq=1
have_py=0; command -v python3 >/dev/null 2>&1 && have_py=1

# Read a top-level array from the expectations file, one element per line.
# String elements -> raw value; object elements -> compact JSON (one line).
jget() { # jget <key>   e.g. jget services
  local key="$1"
  if [ "$have_jq" = 1 ]; then
    jq -rc ".${key}[]?" "$EXPECT_FILE" 2>/dev/null
  elif [ "$have_py" = 1 ]; then
    python3 - "$EXPECT_FILE" "$key" <<'PY' 2>/dev/null
import sys,json
d=json.load(open(sys.argv[1]))
for x in d.get(sys.argv[2],[]) or []:
    print(x if not isinstance(x,(dict,list)) else json.dumps(x,separators=(',',':')))
PY
  fi
}

checks_json=""
overall=true
add_check() { # name ok detail [advisory]
  local name="$1" ok="$2" detail="$3" advisory="${4:-0}"
  # Replace ALL control chars (newline, tab, CR, ANSI ESC, etc. = 0x00-0x1F)
  # with spaces, THEN escape backslash and double-quote. A raw control char
  # left in a JSON string is invalid and crashes the orchestrator's parser.
  detail=$(printf '%s' "$detail" | tr '\000-\037' ' ' | sed 's/\\/\\\\/g; s/"/\\"/g')
  local adv=false; [ "$advisory" = "1" ] && adv=true
  [ "$ok" = "false" ] && [ "$advisory" != "1" ] && overall=false
  checks_json="${checks_json}{\"name\":\"${name}\",\"ok\":${ok},\"advisory\":${adv},\"detail\":\"${detail}\"},"
}

# ---- 1. cloud-init final status ---------------------------------------------
ci_status="$(cloud-init status 2>/dev/null | awk -F': ' '/status:/{print $2}' | head -1)"
[ -z "$ci_status" ] && ci_status="unknown"
ci_long="$(cloud-init status --long 2>/dev/null | tr '\n' ' ')"
case "$ci_status" in
  done)         add_check "cloud_init_status" "true"  "status: done" ;;
  degraded*|*degraded*) add_check "cloud_init_status" "true" "status: ${ci_status} (recoverable) ${ci_long}" 1 ;;
  *)            add_check "cloud_init_status" "false" "status: ${ci_status} ${ci_long}" ;;
esac

# ---- 2. cloud-init error count ----------------------------------------------
ci_err_count=0
if [ "$have_py" = 1 ]; then
  ci_err_count="$(cloud-init status --format json 2>/dev/null | python3 -c 'import json,sys;
try: print(len(json.load(sys.stdin).get("errors",[])))
except Exception: print(0)' 2>/dev/null)"
elif [ "$have_jq" = 1 ]; then
  ci_err_count="$(cloud-init status --format json 2>/dev/null | jq -r '.errors|length' 2>/dev/null)"
fi
[ -z "$ci_err_count" ] && ci_err_count=0

# ---- 3. failed systemd units -------------------------------------------------
failed_units="$(systemctl --failed --no-legend --plain 2>/dev/null | awk '{print $1}' | tr '\n' ' ')"
if [ -z "$failed_units" ]; then
  add_check "no_failed_units" "true" "none"
else
  add_check "no_failed_units" "false" "failed: ${failed_units}"
fi

# ---- 4. bootstrap log: present + success marker; error-scan is advisory ------
# Prefer the app's "<app>-bootstrap.log" over any base-image "bootstrap.log".
boot_log="$(ls -1 /var/log/*-bootstrap*.log 2>/dev/null | head -1)"
[ -z "$boot_log" ] && boot_log="$(ls -1 /var/log/*bootstrap*.log 2>/dev/null | grep -v '/bootstrap\.log$' | head -1)"
if [ -n "$boot_log" ] && [ -f "$boot_log" ]; then
  if grep -qiE '===.*bootstrap.*(finished|complete|completed|done)|bootstrap (finished|complete|completed|done)' "$boot_log"; then
    add_check "bootstrap_completed" "true" "marker found in $(basename "$boot_log")"
  else
    add_check "bootstrap_completed" "false" "no completion marker in $(basename "$boot_log"); tail: $(tail -3 "$boot_log" 2>/dev/null)"
  fi
  err_lines="$(grep -iE '\b(ERROR|FATAL|Traceback|command not found|E: Unable)\b' "$boot_log" 2>/dev/null \
      | grep -viE 'error[._-]?(log|page|document|handler|reporting|=0)|no error|set -|ignore|warn' | tail -5 | tr '\n' '|')"
  if [ -z "$err_lines" ]; then
    add_check "bootstrap_no_errors" "true" "no error lines" 1
  else
    add_check "bootstrap_no_errors" "false" "error lines: ${err_lines}" 1
  fi
else
  add_check "bootstrap_completed" "false" "no /var/log/*bootstrap*.log found"
fi

# ---- 5. expected services active --------------------------------------------
while IFS= read -r svc; do
  [ -z "$svc" ] && continue
  load="$(systemctl show "$svc" -p LoadState --value 2>/dev/null)"
  if [ "$load" != "loaded" ]; then
    add_check "service:${svc}" "false" "not installed (LoadState=${load:-unknown})"
    continue
  fi
  active="$(systemctl is-active "$svc" 2>/dev/null)"
  if [ "$active" = "active" ]; then
    add_check "service:${svc}" "true" "active"
  else
    typ="$(systemctl show "$svc" -p Type --value 2>/dev/null)"
    res="$(systemctl show "$svc" -p Result --value 2>/dev/null)"
    sub="$(systemctl show "$svc" -p SubState --value 2>/dev/null)"
    if [ "$typ" = "oneshot" ] && [ "$res" = "success" ] && [ "$sub" = "exited" ]; then
      add_check "service:${svc}" "true" "oneshot exited success"
    else
      add_check "service:${svc}" "false" "is-active=${active} type=${typ} result=${res}"
    fi
  fi
done < <(jget services)

# ---- 6. expected ports listening --------------------------------------------
listening=" $(ss -ltnH 2>/dev/null | awk '{print $4}' | sed -E 's/.*:([0-9]+)$/\1/' | sort -un | tr '\n' ' ') "
while IFS= read -r port; do
  [ -z "$port" ] && continue
  if printf '%s' "$listening" | grep -q " ${port} "; then
    add_check "port:${port}" "true" "listening"
  else
    add_check "port:${port}" "false" "not listening (open:${listening})"
  fi
done < <(jget ports)

# ---- 7. expected files exist -------------------------------------------------
while IFS= read -r file; do
  [ -z "$file" ] && continue
  if [ -e "$file" ]; then add_check "file:${file}" "true" "exists"
  else add_check "file:${file}" "false" "missing"; fi
done < <(jget files)

# ---- 8. http checks  [{port,path,expect_code,expect_text}] -------------------
while IFS= read -r h; do
  [ -z "$h" ] && continue
  hp="$(printf '%s' "$h" | sed -n 's/.*"port"[: ]*\([0-9]*\).*/\1/p')"
  hpath="$(printf '%s' "$h" | sed -n 's/.*"path"[: ]*"\([^"]*\)".*/\1/p')"
  hcode="$(printf '%s' "$h" | sed -n 's/.*"expect_code"[: ]*\([0-9]*\).*/\1/p')"
  htext="$(printf '%s' "$h" | sed -n 's/.*"expect_text"[: ]*"\([^"]*\)".*/\1/p')"
  [ -z "$hpath" ] && hpath="/"
  scheme="http"; { [ "$hp" = "443" ] || [ "$hp" = "8443" ]; } && scheme="https"
  url="${scheme}://127.0.0.1:${hp}${hpath}"
  code="$(curl -ks -m 20 -o /tmp/.ci_http_body -w '%{http_code}' "$url" 2>/dev/null)"
  ok=true; detail="code=${code}"
  if [ -n "$hcode" ] && [ "$code" != "$hcode" ]; then ok=false; detail="${detail} expected=${hcode}"; fi
  if [ -n "$htext" ]; then
    if grep -qiF "$htext" /tmp/.ci_http_body 2>/dev/null; then detail="${detail} text-ok"
    else ok=false; detail="${detail} missing-text:${htext}"; fi
  fi
  rm -f /tmp/.ci_http_body
  add_check "http:${hp}${hpath}" "$ok" "$detail"
done < <(jget http)

# ---- 9. arbitrary commands  [{cmd,expect_code}] -----------------------------
while IFS= read -r c; do
  [ -z "$c" ] && continue
  ccmd="$(printf '%s' "$c" | sed -n 's/.*"cmd"[: ]*"\([^"]*\)".*/\1/p')"
  [ -z "$ccmd" ] && continue
  cexp="$(printf '%s' "$c" | sed -n 's/.*"expect_code"[: ]*\([0-9]*\).*/\1/p')"
  [ -z "$cexp" ] && cexp=0
  out="$(bash -lc "$ccmd" 2>&1)"; rc=$?
  if [ "$rc" = "$cexp" ]; then
    add_check "cmd:${ccmd}" "true" "rc=${rc}"
  else
    add_check "cmd:${ccmd}" "false" "rc=${rc} expected=${cexp} out=$(printf '%s' "$out" | tail -2)"
  fi
done < <(jget commands)

# ---- assemble result ---------------------------------------------------------
checks_json="[${checks_json%,}]"
uptime_s="$(awk '{print int($1)}' /proc/uptime 2>/dev/null)"
hostn="$(hostname 2>/dev/null)"
printf '{"host":"%s","uptime_s":%s,"cloud_init_status":"%s","cloud_init_errors":%s,"overall_pass":%s,"checks":%s}\n' \
  "$hostn" "${uptime_s:-0}" "$ci_status" "${ci_err_count:-0}" "$overall" "$checks_json"
