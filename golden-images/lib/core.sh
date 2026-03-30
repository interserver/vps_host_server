#!/usr/bin/env bash
# lib/core.sh — Colors, logging, and utility functions

# ── Colors ──────────────────────────────────────────────────────────────────
if [[ -t 1 ]] && [[ -z "${NO_COLOR:-}" ]]; then
  GB_R=$'\033[0;31m'  GB_G=$'\033[0;32m'  GB_Y=$'\033[0;33m'
  GB_B=$'\033[0;34m'  GB_C=$'\033[0;36m'  GB_M=$'\033[0;35m'
  GB_BOLD=$'\033[1m'  GB_DIM=$'\033[2m'   GB_RST=$'\033[0m'
  GB_BG_R=$'\033[41m' GB_BG_G=$'\033[42m' GB_BG_Y=$'\033[43m'
else
  GB_R='' GB_G='' GB_Y='' GB_B='' GB_C='' GB_M=''
  GB_BOLD='' GB_DIM='' GB_RST='' GB_BG_R='' GB_BG_G='' GB_BG_Y=''
fi

export GB_R GB_G GB_Y GB_B GB_C GB_M GB_BOLD GB_DIM GB_RST GB_BG_R GB_BG_G GB_BG_Y

# ── Logging ─────────────────────────────────────────────────────────────────
_gb_ts() { date '+%H:%M:%S'; }

gb_log_info()   { printf '%s[%s INFO]%s  %s\n' "$GB_B" "$(_gb_ts)" "$GB_RST" "$*"; }
gb_log_ok()     { printf '%s[%s  OK ]%s  %s\n' "$GB_G" "$(_gb_ts)" "$GB_RST" "$*"; }
gb_log_warn()   { printf '%s[%s WARN]%s  %s\n' "$GB_Y" "$(_gb_ts)" "$GB_RST" "$*" >&2; }
gb_log_error()  { printf '%s[%s FAIL]%s  %s\n' "$GB_R" "$(_gb_ts)" "$GB_RST" "$*" >&2; }
gb_log_header() { printf '\n%s==> %s%s\n' "$GB_BOLD" "$*" "$GB_RST"; }
gb_log_debug()  { [[ "${GB_DEBUG:-0}" == "1" ]] && printf '%s[%s DEBG]%s  %s\n' "$GB_DIM" "$(_gb_ts)" "$GB_RST" "$*"; }

# ── Utility ─────────────────────────────────────────────────────────────────

# Sanitize a string for use as a filename
gb_safe_name() {
  echo "$1" | sed 's#[/:]#_#g; s#[^a-zA-Z0-9._-]#_#g'
}

# Check available disk space in MB
gb_disk_avail_mb() {
  local dir="${1:-.}"
  df -BM --output=avail "$dir" 2>/dev/null | tail -1 | tr -dc '0-9'
}

# Check disk usage percentage
gb_disk_pct() {
  local dir="${1:-.}"
  df --output=pcent "$dir" 2>/dev/null | tail -1 | tr -dc '0-9'
}

# Free disk space by pruning Docker
gb_disk_cleanup() {
  gb_log_warn "Low disk space. Running Docker cleanup..."
  docker system prune -f --volumes 2>/dev/null || true
  docker builder prune -f 2>/dev/null || true
}

# Ensure minimum disk space, cleanup if needed
gb_disk_ensure() {
  local min_mb="${1:-$GB_MIN_DISK_MB}"
  local avail
  avail="$(gb_disk_avail_mb "$GB_WORK_DIR")" || avail=999999
  if [[ "${avail:-999999}" -lt "$min_mb" ]]; then
    gb_disk_cleanup
  fi
}

# JSON-safe string escaping (no jq dependency)
gb_json_str() {
  local s="$1"
  s="${s//\\/\\\\}"
  s="${s//\"/\\\"}"
  s="${s//$'\n'/\\n}"
  s="${s//$'\t'/\\t}"
  s="${s//$'\r'/}"
  printf '"%s"' "$s"
}
