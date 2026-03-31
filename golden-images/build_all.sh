#!/usr/bin/env bash
set -euo pipefail

# ============================================================================
# build_all.sh — Docker SSH Golden Image Builder
# ============================================================================
# Builds and tests SSH-enabled Docker containers for all major Linux distros.
# Features: tmux split-window display, queue-based parallel workers, error
# aggregation, disk space management, Docker Hub rate-limit handling.
#
# Usage: ./build_all.sh [OPTIONS] [matrix_file]
#   -p, --parallel N     Number of parallel builds (default: 6)
#   -d, --dir DIR        Working directory (default: ./build)
#   --no-tmux            Disable tmux UI (fallback to xargs)
#   --no-verify          Skip SSH verification
#   --push               Push images after build
#   --clean              Clean previous build artifacts
#   -h, --help           Show help
#
# Modes (internal — used by tmux panes):
#   --worker ID WORKDIR  Run as build worker
#   --summary WORKDIR    Run as summary monitor
# ============================================================================

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT_PATH="$(readlink -f "${BASH_SOURCE[0]}" 2>/dev/null || echo "${BASH_SOURCE[0]}")"
RENDER_SCRIPT="$ROOT_DIR/render_dockerfile.sh"
VERIFY_SCRIPT="$ROOT_DIR/verify_image.sh"

# ── Defaults ────────────────────────────────────────────────────────────────
MATRIX_FILE="${ROOT_DIR}/images.matrix"
ROOT_PASSWORD="${ROOT_PASSWORD:-InterServer!23}"
REGISTRY_PREFIX="${REGISTRY_PREFIX:-interserver}"
PUSH_IMAGES="${PUSH_IMAGES:-1}"
VERIFY_IMAGES="${VERIFY_IMAGES:-1}"
PARALLELISM="${PARALLELISM:-2}"
WORK_DIR="${WORK_DIR:-$ROOT_DIR/build}"
MAX_RETRIES="${MAX_RETRIES:-3}"
RETRY_BASE_WAIT="${RETRY_BASE_WAIT:-30}"
RATE_LIMIT_WAIT="${RATE_LIMIT_WAIT:-900}"
MIN_DISK_MB="${MIN_DISK_MB:-10000}"
USE_TMUX="${USE_TMUX:-auto}"
TMUX_SESSION="golden-build"
CLEAN_FIRST="${CLEAN_FIRST:-1}"

# ── Colors ──────────────────────────────────────────────────────────────────
if [[ -t 1 ]] && [[ -z "${NO_COLOR:-}" ]]; then
  _R=$'\033[0;31m' _G=$'\033[0;32m' _Y=$'\033[0;33m'
  _B=$'\033[0;34m' _C=$'\033[0;36m' _M=$'\033[0;35m'
  _BOLD=$'\033[1m' _DIM=$'\033[2m' _RST=$'\033[0m'
else
  _R='' _G='' _Y='' _B='' _C='' _M='' _BOLD='' _DIM='' _RST=''
fi

_ts() { date '+%H:%M:%S'; }
log_info()   { printf '%s[%s INFO]%s  %s\n' "$_B" "$(_ts)" "$_RST" "$*"; }
log_ok()     { printf '%s[%s  OK ]%s  %s\n' "$_G" "$(_ts)" "$_RST" "$*"; }
log_warn()   { printf '%s[%s WARN]%s  %s\n' "$_Y" "$(_ts)" "$_RST" "$*" >&2; }
log_error()  { printf '%s[%s FAIL]%s  %s\n' "$_R" "$(_ts)" "$_RST" "$*" >&2; }
log_header() { printf '\n%s==> %s%s\n' "$_BOLD" "$*" "$_RST"; }

# ── Argument Parsing ────────────────────────────────────────────────────────
_mode="main"
_worker_id=""
_worker_workdir=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --worker)     _mode="worker"; _worker_id="$2"; _worker_workdir="$3"; shift 3 ;;
    --summary)    _mode="summary"; _worker_workdir="$2"; shift 2 ;;
    -p|--parallel) PARALLELISM="$2"; shift 2 ;;
    -d|--dir)     WORK_DIR="$2"; shift 2 ;;
    --no-tmux)    USE_TMUX="no"; shift ;;
    --no-verify)  VERIFY_IMAGES=0; shift ;;
    --push)       PUSH_IMAGES=1; shift ;;
    --clean)      CLEAN_FIRST=1; shift ;;
    -h|--help)
      sed -n '2,/^# =====/{ /^#/s/^# \?//p }' "${BASH_SOURCE[0]}"
      exit 0
      ;;
    -*)
      echo "Unknown option: $1" >&2; exit 1 ;;
    *)
      MATRIX_FILE="$1"; shift ;;
  esac
done

# ── Directory layout ────────────────────────────────────────────────────────
RESULTS_DIR="$WORK_DIR/.results"
LOG_DIR="$WORK_DIR/logs"
QUEUE_DIR="$WORK_DIR/.queue"
STATUS_DIR="$WORK_DIR/.status"
ERRORS_DIR="$WORK_DIR/.errors"

# ============================================================================
# WORKER MODE
# ============================================================================
run_worker() {
  local wid="$1"
  local wd="$2"
  WORK_DIR="$wd"
  RESULTS_DIR="$WORK_DIR/.results"
  LOG_DIR="$WORK_DIR/logs"
  QUEUE_DIR="$WORK_DIR/.queue"
  STATUS_DIR="$WORK_DIR/.status"
  ERRORS_DIR="$WORK_DIR/.errors"

  # Read config written by main
  # shellcheck disable=SC1091
  [[ -f "$WORK_DIR/.config" ]] && source "$WORK_DIR/.config"

  local RENDER_SCRIPT VERIFY_SCRIPT
  RENDER_SCRIPT="$(cat "$WORK_DIR/.render_path" 2>/dev/null || echo "$ROOT_DIR/render_dockerfile.sh")"
  VERIFY_SCRIPT="$(cat "$WORK_DIR/.verify_path" 2>/dev/null || echo "$ROOT_DIR/verify_image.sh")"

  local status_file="$STATUS_DIR/worker-${wid}"
  local claimed_jobs=0
  local built_ok=0
  local built_fail=0

  update_status() { echo "$*" > "$status_file"; }
  update_status "idle"

  printf '%s[Worker %s]%s Starting...\n' "$_C" "$wid" "$_RST"

  while true; do
    # ── Claim next job from queue ──
    local job_file=""
    for jf in "$QUEUE_DIR/pending/"*; do
      [[ -f "$jf" ]] || continue
      local jname
      jname="$(basename "$jf")"
      if mv "$jf" "$QUEUE_DIR/active/${jname}" 2>/dev/null; then
        job_file="$QUEUE_DIR/active/${jname}"
        break
      fi
    done

    if [[ -z "$job_file" ]]; then
      update_status "done"
      printf '\n%s[Worker %s]%s Queue empty. Built %s ok, %s failed.\n' \
        "$_G" "$wid" "$_RST" "$built_ok" "$built_fail"
      break
    fi

    claimed_jobs=$((claimed_jobs + 1))

    # Read job
    local base="" tag=""
    # shellcheck disable=SC1090
    source "$job_file"
    local safe_name
    safe_name="$(echo "$tag" | sed 's#[/:]#_#g')"

    update_status "building $base -> $tag"
    printf '\n%s[Worker %s]%s Building: %s%s%s -> %s\n' \
      "$_C" "$wid" "$_RST" "$_BOLD" "$base" "$_RST" "$tag"

    local out_dir="$WORK_DIR/$safe_name"
    local log_file="$LOG_DIR/${safe_name}.log"
    local t0 elapsed
    t0=$(date +%s)
    : > "$log_file"

    # ── Check disk space ──
    worker_check_disk

    # ── Render Dockerfile ──
    if ! "$RENDER_SCRIPT" "$base" "$tag" "$ROOT_PASSWORD" "$out_dir" >> "$log_file" 2>&1; then
      elapsed=$(( $(date +%s) - t0 ))
      log_error "[Worker $wid] Dockerfile render failed for $base (${elapsed}s)"
      printf 'RENDER_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
      record_error "$log_file" "$safe_name"
      mv "$job_file" "$QUEUE_DIR/failed/$(basename "$job_file")" 2>/dev/null || true
      built_fail=$((built_fail + 1))
      continue
    fi

    # ── Build with retry ──
    local attempt=0
    local build_ok=false

    while true; do
      attempt=$((attempt + 1))
      update_status "building $base (attempt $attempt)"

      if [[ $attempt -gt 1 ]]; then
        printf '%s[Worker %s]%s  Retry attempt %s/%s\n' \
          "$_Y" "$wid" "$_RST" "$attempt" "$((MAX_RETRIES + 1))"
      fi

      # Build with log tee
      if docker build --pull --network=host \
           --build-arg ROOT_PASSWORD="$ROOT_PASSWORD" \
           -t "$tag" "$out_dir" 2>&1 | tee -a "$log_file"; then
        build_ok=true
        break
      fi

      # ── Classify the error ──
      local err_class
      err_class="$(classify_build_error "$log_file")"

      case "$err_class" in
        SCHEMA1)
          elapsed=$(( $(date +%s) - t0 ))
          log_warn "[Worker $wid] Schema v1 skipped: $base"
          printf 'SCHEMA1_SKIP\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
          mv "$job_file" "$QUEUE_DIR/failed/$(basename "$job_file")" 2>/dev/null || true
          built_fail=$((built_fail + 1))
          continue 2  # skip to next job
          ;;
        RATE_LIMIT)
          if [[ $attempt -le $MAX_RETRIES ]]; then
            local rl_wait
            rl_wait="$(detect_rate_limit_wait "$log_file")"
            log_warn "[Worker $wid] Rate limited. Waiting ${rl_wait}s..."
            update_status "rate-limited (${rl_wait}s) $base"
            sleep "$rl_wait"
            : > "$log_file"
            continue
          fi
          ;;
        RETRYABLE)
          if [[ $attempt -le $MAX_RETRIES ]]; then
            local wait_time=$(( RETRY_BASE_WAIT * (2 ** (attempt - 1)) ))
            log_warn "[Worker $wid] Retryable error, backing off ${wait_time}s..."
            update_status "retry-wait (${wait_time}s) $base"
            sleep "$wait_time"
            : > "$log_file"
            continue
          fi
          ;;
      esac

      # Non-retryable or retries exhausted
      break
    done

    if [[ "$build_ok" != "true" ]]; then
      elapsed=$(( $(date +%s) - t0 ))
      log_error "[Worker $wid] Build FAILED: $base (${elapsed}s, $attempt attempts)"
      printf 'BUILD_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
      record_error "$log_file" "$safe_name"
      mv "$job_file" "$QUEUE_DIR/failed/$(basename "$job_file")" 2>/dev/null || true
      built_fail=$((built_fail + 1))
      continue
    fi

    # ── Verify SSH ──
    if [[ "$VERIFY_IMAGES" == "1" ]]; then
      update_status "verifying $tag"
      printf '%s[Worker %s]%s  Verifying SSH: %s\n' "$_B" "$wid" "$_RST" "$tag"
      if ! "$VERIFY_SCRIPT" "$tag" "$ROOT_PASSWORD" >> "$log_file" 2>&1; then
        elapsed=$(( $(date +%s) - t0 ))
        log_warn "[Worker $wid] Verify FAILED: $tag (${elapsed}s)"
        printf 'VERIFY_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
        record_error "$log_file" "$safe_name"
        mv "$job_file" "$QUEUE_DIR/failed/$(basename "$job_file")" 2>/dev/null || true
        built_fail=$((built_fail + 1))
        continue
      fi
    fi

    # ── Push ──
    if [[ "$PUSH_IMAGES" == "1" ]]; then
      local detain_tag=""
      if detain_tag="$(derive_detain_tag "$tag")"; then
        update_status "tagging $tag -> $detain_tag"
        if ! docker tag "$tag" "$detain_tag" >> "$log_file" 2>&1; then
          elapsed=$(( $(date +%s) - t0 ))
          log_error "[Worker $wid] Tag FAILED: $tag -> $detain_tag (${elapsed}s)"
          printf 'TAG_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
          mv "$job_file" "$QUEUE_DIR/failed/$(basename "$job_file")" 2>/dev/null || true
          built_fail=$((built_fail + 1))
          continue
        fi
        update_status "pushing $detain_tag"
        if ! docker push "$detain_tag" >> "$log_file" 2>&1; then
          elapsed=$(( $(date +%s) - t0 ))
          log_error "[Worker $wid] Push FAILED: $detain_tag (${elapsed}s)"
          printf 'PUSH_FAIL\t%s\t%s\n' "$base" "$detain_tag" > "$RESULTS_DIR/${safe_name}.fail"
          mv "$job_file" "$QUEUE_DIR/failed/$(basename "$job_file")" 2>/dev/null || true
          built_fail=$((built_fail + 1))
          continue
        fi
      else
        elapsed=$(( $(date +%s) - t0 ))
        log_error "[Worker $wid] Could not derive detain tag from: $tag (${elapsed}s)"
        printf 'TAG_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
        mv "$job_file" "$QUEUE_DIR/failed/$(basename "$job_file")" 2>/dev/null || true
        built_fail=$((built_fail + 1))
        continue
      fi
    fi

    elapsed=$(( $(date +%s) - t0 ))
    log_ok "[Worker $wid] SUCCESS: $tag (${elapsed}s)"
    touch "$RESULTS_DIR/${safe_name}.ok"
    mv "$job_file" "$QUEUE_DIR/done/$(basename "$job_file")" 2>/dev/null || true
    built_ok=$((built_ok + 1))
  done

  update_status "finished ok=$built_ok fail=$built_fail"
  # Keep pane open so user can review
  printf '\n%s[Worker %s] Press Enter to close...%s\n' "$_DIM" "$wid" "$_RST"
  read -r 2>/dev/null || sleep 86400
}

# ── Worker helpers ──

classify_build_error() {
  local log_file="$1"
  if grep -qiE 'schema 1 has been removed|manifest version 2, schema 1|v1 manifest|unsupported media type' "$log_file" 2>/dev/null; then
    echo "SCHEMA1"
  elif grep -qiE '429|Too Many Requests|rate.limit|toomanyrequests|You have reached your pull rate limit|TOOMANYREQUESTS' "$log_file" 2>/dev/null; then
    echo "RATE_LIMIT"
  elif grep -qiE 'connection reset|TLS handshake timeout|dial tcp.*timeout|i/o timeout|deadline exceeded|DeadlineExceeded|unexpected EOF|server misbehaving|no such host|network unreachable|connection refused.*registry|net/http.*request canceled' "$log_file" 2>/dev/null; then
    echo "RETRYABLE"
  else
    echo "FAIL"
  fi
}

detect_rate_limit_wait() {
  local log_file="$1"
  # Try to parse Retry-After value from log
  local retry_after
  retry_after="$(grep -oiP 'retry.after[:\s]*\K[0-9]+' "$log_file" 2>/dev/null | head -1)" || true
  if [[ -n "$retry_after" ]] && [[ "$retry_after" -gt 0 ]] 2>/dev/null; then
    echo "$retry_after"
  else
    # Default: Docker Hub anonymous rate limit resets after ~15 minutes
    echo "$RATE_LIMIT_WAIT"
  fi
}

derive_detain_tag() {
  local source_tag="$1"
  if [[ "$source_tag" =~ ^[^/]+/([^-]+-[^-]+)-ssh$ ]]; then
    printf 'detain/interserver:%s\n' "${BASH_REMATCH[1]}"
  else
    return 1
  fi
}

record_error() {
  local log_file="$1"
  local safe_name="$2"
  # Extract last meaningful error lines from log
  local error_lines
  error_lines="$(grep -iE 'error|fail|fatal|denied|refused|timeout|cannot|unable' "$log_file" 2>/dev/null | tail -5)" || true
  if [[ -n "$error_lines" ]]; then
    echo "$error_lines" > "$ERRORS_DIR/${safe_name}.err"
  fi
}

worker_check_disk() {
  local avail_mb
  avail_mb="$(df -BM --output=avail "$WORK_DIR" 2>/dev/null | tail -1 | tr -dc '0-9')" || avail_mb=999999
  if [[ "${avail_mb:-999999}" -lt "$MIN_DISK_MB" ]]; then
    log_warn "[Worker $wid] Low disk space (${avail_mb}MB). Cleaning..."
    docker system prune -f --volumes 2>/dev/null || true
    docker image prune -f 2>/dev/null || true
    docker builder prune -f 2>/dev/null || true
  fi
}

# ============================================================================
# SUMMARY MODE
# ============================================================================
run_summary() {
  local wd="$1"
  WORK_DIR="$wd"
  RESULTS_DIR="$WORK_DIR/.results"
  QUEUE_DIR="$WORK_DIR/.queue"
  STATUS_DIR="$WORK_DIR/.status"
  ERRORS_DIR="$WORK_DIR/.errors"
  LOG_DIR="$WORK_DIR/logs"

  # shellcheck disable=SC1091
  [[ -f "$WORK_DIR/.config" ]] && source "$WORK_DIR/.config"

  local total_jobs
  total_jobs="$(cat "$WORK_DIR/.total_jobs" 2>/dev/null || echo 0)"
  local wall_start
  wall_start="$(cat "$WORK_DIR/.wall_start" 2>/dev/null || date +%s)"

  while true; do
    clear
    local now elapsed_s elapsed_m elapsed_h
    now=$(date +%s)
    elapsed_s=$(( now - wall_start ))
    elapsed_m=$(( elapsed_s / 60 ))
    elapsed_h=$(( elapsed_s / 3600 ))

    local pending active done_ok failed
    pending=$(find "$QUEUE_DIR/pending" -maxdepth 1 -type f 2>/dev/null | wc -l)
    active=$(find "$QUEUE_DIR/active" -maxdepth 1 -type f 2>/dev/null | wc -l)
    done_ok=$(find "$RESULTS_DIR" -maxdepth 1 -name '*.ok' 2>/dev/null | wc -l)
    failed=$(find "$RESULTS_DIR" -maxdepth 1 -name '*.fail' 2>/dev/null | wc -l)
    local completed=$((done_ok + failed))

    # ── Header ──
    printf '%s╔══════════════════════════════════════════════════════════════╗%s\n' "$_BOLD" "$_RST"
    printf '%s║       Docker SSH Golden Image Builder — Summary            ║%s\n' "$_BOLD" "$_RST"
    printf '%s╚══════════════════════════════════════════════════════════════╝%s\n' "$_BOLD" "$_RST"
    echo ""

    # ── Progress bar ──
    local pct=0
    [[ "$total_jobs" -gt 0 ]] && pct=$(( completed * 100 / total_jobs ))
    local bar_width=50
    local filled=$(( pct * bar_width / 100 ))
    local empty=$(( bar_width - filled ))
    printf '  Progress: ['
    printf '%s' "$_G"
    for ((i=0; i<filled; i++)); do printf '#'; done
    printf '%s' "$_RST"
    for ((i=0; i<empty; i++)); do printf '.'; done
    printf '] %3d%%  (%d/%d)\n' "$pct" "$completed" "$total_jobs"
    echo ""

    # ── Stats ──
    printf '  %s%-14s%s %s\n' "$_B" "Elapsed:" "$_RST" "$(printf '%02d:%02d:%02d' "$elapsed_h" "$((elapsed_m % 60))" "$((elapsed_s % 60))")"
    printf '  %s%-14s%s %d\n' "$_B" "Pending:" "$_RST" "$pending"
    printf '  %s%-14s%s %d\n' "$_C" "Active:" "$_RST" "$active"
    printf '  %s%-14s%s %d\n' "$_G" "Succeeded:" "$_RST" "$done_ok"
    printf '  %s%-14s%s %d\n' "$_R" "Failed:" "$_RST" "$failed"
    echo ""

    # ── Disk space ──
    local disk_avail disk_used disk_pct
    disk_avail="$(df -BG --output=avail "$WORK_DIR" 2>/dev/null | tail -1 | tr -dc '0-9')" || disk_avail="?"
    disk_used="$(df -BG --output=used "$WORK_DIR" 2>/dev/null | tail -1 | tr -dc '0-9')" || disk_used="?"
    disk_pct="$(df --output=pcent "$WORK_DIR" 2>/dev/null | tail -1 | tr -dc '0-9')" || disk_pct="?"
    printf '  %sDisk:%s %sG used, %sG free (%s%% used)\n' "$_DIM" "$_RST" "$disk_used" "$disk_avail" "$disk_pct"
    echo ""

    # ── Active workers ──
    printf '  %s── Worker Status ──%s\n' "$_BOLD" "$_RST"
    for sf in "$STATUS_DIR"/worker-*; do
      [[ -f "$sf" ]] || continue
      local wname status_line
      wname="$(basename "$sf")"
      status_line="$(cat "$sf" 2>/dev/null || echo "unknown")"
      local status_color="$_C"
      case "$status_line" in
        done*|finished*) status_color="$_G" ;;
        rate-limited*)   status_color="$_Y" ;;
        retry-wait*)     status_color="$_Y" ;;
        idle)            status_color="$_DIM" ;;
      esac
      printf '  %s%-12s%s %s%s%s\n' "$_B" "$wname:" "$_RST" "$status_color" "$status_line" "$_RST"
    done
    echo ""

    # ── Recent results ──
    printf '  %s── Recent Results ──%s\n' "$_BOLD" "$_RST"
    local recent_count=0
    for rf in $(ls -t "$RESULTS_DIR"/*.ok "$RESULTS_DIR"/*.fail 2>/dev/null | head -8); do
      [[ -f "$rf" ]] || continue
      local rname rtype
      rname="$(basename "$rf")"
      rname="${rname%.*}"
      rtype="${rf##*.}"
      if [[ "$rtype" == "ok" ]]; then
        printf '  %s  OK %s %s\n' "$_G" "$_RST" "$rname"
      else
        local reason=""
        [[ -f "$rf" ]] && reason="$(cut -f1 < "$rf" 2>/dev/null || true)"
        printf '  %sFAIL%s %s (%s)\n' "$_R" "$_RST" "$rname" "$reason"
      fi
      recent_count=$((recent_count + 1))
    done
    [[ $recent_count -eq 0 ]] && printf '  %s(none yet)%s\n' "$_DIM" "$_RST"
    echo ""

    # ── Check if all workers are done ──
    local all_done=true
    for sf in "$STATUS_DIR"/worker-*; do
      [[ -f "$sf" ]] || continue
      local st
      st="$(cat "$sf" 2>/dev/null || echo "")"
      case "$st" in
        done*|finished*) ;;
        *) all_done=false ;;
      esac
    done

    if [[ "$all_done" == "true" ]] && [[ "$completed" -gt 0 || "$pending" -eq 0 ]]; then
      echo ""
      printf '  %s══════════ ALL BUILDS COMPLETE ══════════%s\n' "$_BOLD" "$_RST"
      echo ""
      generate_final_report "$wd"
      printf '\n  %sReport saved. Press Enter to close all panes...%s\n' "$_DIM" "$_RST"
      read -r 2>/dev/null || sleep 86400
      # Kill entire tmux session so user doesn't need to close each worker pane
      tmux kill-session -t golden-build 2>/dev/null || true
      break
    fi

    sleep 3
  done
}

# ============================================================================
# FINAL REPORT (called from summary when all done)
# ============================================================================
generate_final_report() {
  local wd="$1"

  # ── Good / Bad lists ──
  printf '  %s── GOOD Templates (SSH Working) ──%s\n' "$_G" "$_RST"
  local good_count=0
  for f in "$RESULTS_DIR"/*.ok; do
    [[ -f "$f" ]] || continue
    local name
    name="$(basename "$f" .ok)"
    printf '    %s%s%s\n' "$_G" "$name" "$_RST"
    echo "$name" >> "$WORK_DIR/good_templates.txt"
    good_count=$((good_count + 1))
  done
  [[ $good_count -eq 0 ]] && printf '    %s(none)%s\n' "$_DIM" "$_RST"
  echo ""

  printf '  %s── BAD Templates (Failed) ──%s\n' "$_R" "$_RST"
  local bad_count=0
  for f in "$RESULTS_DIR"/*.fail; do
    [[ -f "$f" ]] || continue
    local name reason
    name="$(basename "$f" .fail)"
    reason="$(cut -f1 < "$f" 2>/dev/null || echo "UNKNOWN")"
    printf '    %s%-14s%s %s\n' "$_R" "$reason" "$_RST" "$name"
    echo "$reason $name" >> "$WORK_DIR/bad_templates.txt"
    bad_count=$((bad_count + 1))
  done
  [[ $bad_count -eq 0 ]] && printf '    %s(none)%s\n' "$_DIM" "$_RST"
  echo ""

  # ── Error aggregation: group unique errors ──
  printf '  %s── Errors Grouped by Type ──%s\n' "$_BOLD" "$_RST"
  if [[ -d "$ERRORS_DIR" ]] && find "$ERRORS_DIR" -name '*.err' -print -quit 2>/dev/null | grep -q .; then
    # Build a mapping of normalized error -> list of files
    local -A error_map=()

    for ef in "$ERRORS_DIR"/*.err; do
      [[ -f "$ef" ]] || continue
      local ename
      ename="$(basename "$ef" .err)"

      while IFS= read -r errline; do
        [[ -z "$errline" ]] && continue
        # Normalize: strip timestamps, paths, hashes, specific versions
        local normalized
        normalized="$(echo "$errline" | sed \
          -e 's/[0-9]\{4\}-[0-9]\{2\}-[0-9]\{2\}T[0-9:.]*Z\?//g' \
          -e 's/sha256:[a-f0-9]\{64\}/sha256:HASH/g' \
          -e 's|/tmp/[^ ]*|/tmp/PATH|g' \
          -e 's/[0-9]\{1,3\}\.[0-9]\{1,3\}\.[0-9]\{1,3\}\.[0-9]\{1,3\}/IP/g' \
          -e 's/port [0-9]*/port PORT/g' \
          -e 's/[[:space:]]\+/ /g' \
          -e 's/^ //' -e 's/ $//' \
        )"
        [[ -z "$normalized" ]] && continue

        # Truncate to 120 chars for grouping key
        local key="${normalized:0:120}"

        if [[ -n "${error_map[$key]+x}" ]]; then
          error_map[$key]="${error_map[$key]},$ename"
        else
          error_map[$key]="$ename"
        fi
      done < "$ef"
    done

    local err_idx=0
    for key in "${!error_map[@]}"; do
      err_idx=$((err_idx + 1))
      local files="${error_map[$key]}"
      local file_count
      file_count="$(echo "$files" | tr ',' '\n' | sort -u | wc -l)"

      printf '\n    %s[Error %d]%s (%d template(s))\n' "$_R" "$err_idx" "$_RST" "$file_count"
      printf '    %s%s%s\n' "$_DIM" "$key" "$_RST"
      printf '    Templates: '
      echo "$files" | tr ',' '\n' | sort -u | while IFS= read -r fn; do
        printf '%s ' "$fn"
      done
      echo ""
    done

    [[ $err_idx -eq 0 ]] && printf '    %s(no errors captured)%s\n' "$_DIM" "$_RST"
  else
    printf '    %s(no errors captured)%s\n' "$_DIM" "$_RST"
  fi
  echo ""

  printf '  %s── Output Files ──%s\n' "$_BOLD" "$_RST"
  printf '    Good list:    %s/good_templates.txt\n' "$WORK_DIR"
  printf '    Bad list:     %s/bad_templates.txt\n' "$WORK_DIR"
  printf '    Build logs:   %s/\n' "$LOG_DIR"
  echo ""
}

# ============================================================================
# MAIN MODE
# ============================================================================

# ── Mode dispatch ──
case "$_mode" in
  worker)  run_worker "$_worker_id" "$_worker_workdir"; exit 0 ;;
  summary) run_summary "$_worker_workdir"; exit 0 ;;
esac

# Everything below is main orchestrator mode

# ── Prereq checks ──
for cmd in docker; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    log_error "Required command not found: $cmd"
    exit 1
  fi
done

if [[ ! -x "$RENDER_SCRIPT" ]]; then
  log_error "Render script not found/executable: $RENDER_SCRIPT"
  exit 1
fi

if [[ "$VERIFY_IMAGES" == "1" ]] && [[ ! -x "$VERIFY_SCRIPT" ]]; then
  log_error "Verify script not found/executable: $VERIFY_SCRIPT"
  exit 1
fi

if [[ ! -f "$MATRIX_FILE" ]]; then
  log_error "Matrix file not found: $MATRIX_FILE"
  exit 1
fi

# Resolve tmux availability
if [[ "$USE_TMUX" == "auto" ]]; then
  if command -v tmux >/dev/null 2>&1 && [[ -z "${TMUX:-}" ]]; then
    USE_TMUX="yes"
  else
    USE_TMUX="no"
  fi
fi

# ── Clean ──
if [[ "$CLEAN_FIRST" == "1" ]]; then
  log_info "Cleaning previous build artifacts..."
  rm -rf "$WORK_DIR"
fi

mkdir -p "$WORK_DIR" "$RESULTS_DIR" "$LOG_DIR" \
  "$QUEUE_DIR/pending" "$QUEUE_DIR/active" "$QUEUE_DIR/done" "$QUEUE_DIR/failed" \
  "$STATUS_DIR" "$ERRORS_DIR"
rm -f "$RESULTS_DIR"/*.ok "$RESULTS_DIR"/*.fail 2>/dev/null || true
rm -f "$STATUS_DIR"/worker-* 2>/dev/null || true
rm -f "$ERRORS_DIR"/*.err 2>/dev/null || true
rm -f "$WORK_DIR/good_templates.txt" "$WORK_DIR/bad_templates.txt" 2>/dev/null || true

# Save config for workers
cat > "$WORK_DIR/.config" <<EOF
ROOT_PASSWORD='${ROOT_PASSWORD}'
REGISTRY_PREFIX='${REGISTRY_PREFIX}'
PUSH_IMAGES='${PUSH_IMAGES}'
VERIFY_IMAGES='${VERIFY_IMAGES}'
MAX_RETRIES='${MAX_RETRIES}'
RETRY_BASE_WAIT='${RETRY_BASE_WAIT}'
RATE_LIMIT_WAIT='${RATE_LIMIT_WAIT}'
MIN_DISK_MB='${MIN_DISK_MB}'
EOF
echo "$RENDER_SCRIPT" > "$WORK_DIR/.render_path"
echo "$VERIFY_SCRIPT" > "$WORK_DIR/.verify_path"

# ── Build plan: parse matrix ──────────────────────────────────────────

normalize_line_to_pairs() {
  local line="$1"
  line="${line%%#*}"
  line="$(echo "$line" | tr -d '\r' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
  [[ -z "$line" ]] && return 0

  if [[ "$line" == *":"* && "$line" == *,* && "$line" != *,*:* ]]; then
    local family versions version base tag
    family="${line%%:*}"
    versions="${line#*:}"
    family="$(echo "$family" | xargs)"

    IFS=',' read -r -a v_arr <<< "$versions"
    for version in "${v_arr[@]}"; do
      version="$(echo "$version" | xargs)"
      [[ -z "$version" ]] && continue
      base="$family:$version"
      tag="$REGISTRY_PREFIX/${family}-${version}-ssh"
      echo "BUILD,$base,$tag"
    done
    return 0
  fi

  IFS=',' read -r col1 col2 _extra <<< "$line"
  col1="$(echo "${col1:-}" | xargs)"
  col2="$(echo "${col2:-}" | xargs)"

  [[ -z "$col1" ]] && return 0

  if [[ -n "$col2" ]]; then
    echo "BUILD,$col1,$col2"
  else
    echo "BUILD,$col1,$REGISTRY_PREFIX/${col1/:/-}-ssh"
  fi
}

log_header "Build Plan"

plan_file="$WORK_DIR/build-plan.csv"
: > "$plan_file"

while IFS= read -r line || [[ -n "$line" ]]; do
  while IFS= read -r normalized; do
    [[ -z "$normalized" ]] && continue
    echo "$normalized" >> "$plan_file"
  done < <(normalize_line_to_pairs "$line")
done < "$MATRIX_FILE"

# ── Create queue ──
job_id=0
while IFS=',' read -r action base tag; do
  [[ "$action" == "BUILD" ]] || continue
  job_id=$((job_id + 1))
  printf 'base="%s"\ntag="%s"\n' "$base" "$tag" > "$QUEUE_DIR/pending/$(printf '%04d' "$job_id")-$(echo "$base" | sed 's#[/:]#_#g')"
done < "$plan_file"

total_jobs="$job_id"
echo "$total_jobs" > "$WORK_DIR/.total_jobs"
date +%s > "$WORK_DIR/.wall_start"

log_info "Queue: $total_jobs image(s), parallelism=$PARALLELISM"
log_info "Retry: up to $MAX_RETRIES retries, base wait ${RETRY_BASE_WAIT}s"
log_info "Rate limit wait: ${RATE_LIMIT_WAIT}s"
log_info "Min disk space: ${MIN_DISK_MB}MB"
log_info "Logs: $LOG_DIR/"

if [[ "$total_jobs" -eq 0 ]]; then
  log_warn "Nothing to build."
  exit 0
fi

# ── Launch builds ──────────────────────────────────────────────────────

if [[ "$USE_TMUX" == "yes" ]]; then
  # ══════════════════════════════════════════════════════════════════════
  # tmux mode: split windows with summary + N workers
  # ══════════════════════════════════════════════════════════════════════

  # Kill existing session if any
  tmux kill-session -t "$TMUX_SESSION" 2>/dev/null || true

  log_header "Launching tmux session: $TMUX_SESSION"
  log_info "Workers: $PARALLELISM panes + 1 summary pane"
  log_info "Attach with: tmux attach -t $TMUX_SESSION"

  # Create session with summary pane
  tmux new-session -d -s "$TMUX_SESSION" -x 220 -y 60 \
    "bash '$SCRIPT_PATH' --summary '$WORK_DIR'"

  # Add worker panes
  for ((i = 1; i <= PARALLELISM; i++)); do
    tmux split-window -t "$TMUX_SESSION" \
      "bash '$SCRIPT_PATH' --worker '$i' '$WORK_DIR'"
    # Rebalance after each split to prevent tiny panes
    tmux select-layout -t "$TMUX_SESSION" tiled 2>/dev/null || true
  done

  # Final layout adjustment
  tmux select-layout -t "$TMUX_SESSION" tiled
  # Select the summary pane (pane 0)
  tmux select-pane -t "${TMUX_SESSION}:0.0"

  # Attach to session
  log_info "Attaching to tmux session..."
  tmux attach -t "$TMUX_SESSION" || {
    log_warn "Could not attach to tmux. Session is running in background."
    log_info "Attach manually: tmux attach -t $TMUX_SESSION"
    # Wait for completion in background mode
    while true; do
      local all_done=true
      for sf in "$STATUS_DIR"/worker-*; do
        [[ -f "$sf" ]] || continue
        local st
        st="$(cat "$sf" 2>/dev/null || echo "")"
        case "$st" in
          done*|finished*) ;;
          *) all_done=false ;;
        esac
      done
      [[ "$all_done" == "true" ]] && break
      sleep 5
    done
  }

else
  # ══════════════════════════════════════════════════════════════════════
  # Non-tmux fallback: xargs parallel mode (original behavior)
  # ══════════════════════════════════════════════════════════════════════

  log_header "Building Images (xargs mode, parallelism=$PARALLELISM)"

  export WORK_DIR RESULTS_DIR LOG_DIR QUEUE_DIR STATUS_DIR ERRORS_DIR
  export ROOT_PASSWORD REGISTRY_PREFIX PUSH_IMAGES VERIFY_IMAGES
  export MAX_RETRIES RETRY_BASE_WAIT RATE_LIMIT_WAIT MIN_DISK_MB
  export RENDER_SCRIPT VERIFY_SCRIPT
  export _R _G _Y _B _C _M _BOLD _DIM _RST
  export -f _ts log_info log_ok log_warn log_error log_header

  # Export worker functions for xargs
  _xargs_build_one() {
    local pair="$1"
    local base="${pair%%,*}"
    local tag="${pair#*,}"
    local safe_name
    safe_name="$(echo "$tag" | sed 's#[/:]#_#g')"
    local out_dir="$WORK_DIR/$safe_name"
    local log_file="$LOG_DIR/${safe_name}.log"
    local t0 elapsed
    t0=$(date +%s)
    : > "$log_file"

    # Check disk space
    local avail_mb
    avail_mb="$(df -BM --output=avail "$WORK_DIR" 2>/dev/null | tail -1 | tr -dc '0-9')" || avail_mb=999999
    if [[ "${avail_mb:-999999}" -lt "$MIN_DISK_MB" ]]; then
      docker system prune -f --volumes 2>/dev/null || true
    fi

    # Render
    log_info "[$tag] Rendering Dockerfile from $base"
    if ! "$RENDER_SCRIPT" "$base" "$tag" "$ROOT_PASSWORD" "$out_dir" >> "$log_file" 2>&1; then
      elapsed=$(( $(date +%s) - t0 ))
      log_error "[$tag] Render failed (${elapsed}s)"
      printf 'RENDER_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
      return 0
    fi

    # Build with retry
    local attempt=0 build_ok=false
    while true; do
      attempt=$((attempt + 1))
      [[ $attempt -gt 1 ]] && log_info "[$tag] Retry $attempt/$((MAX_RETRIES + 1))"
      log_info "[$tag] Building..."

      if docker build --pull --network=host \
           --build-arg ROOT_PASSWORD="$ROOT_PASSWORD" \
           -t "$tag" "$out_dir" >> "$log_file" 2>&1; then
        build_ok=true
        break
      fi

      local err_class
      if grep -qiE 'schema 1 has been removed|manifest version 2, schema 1|v1 manifest' "$log_file" 2>/dev/null; then
        err_class="SCHEMA1"
      elif grep -qiE '429|Too Many Requests|rate.limit|toomanyrequests' "$log_file" 2>/dev/null; then
        err_class="RATE_LIMIT"
      elif grep -qiE 'connection reset|TLS handshake timeout|dial tcp.*timeout|i/o timeout|deadline exceeded|unexpected EOF|server misbehaving' "$log_file" 2>/dev/null; then
        err_class="RETRYABLE"
      else
        err_class="FAIL"
      fi

      case "$err_class" in
        SCHEMA1)
          log_warn "[$tag] Schema v1 — skipping"
          printf 'SCHEMA1_SKIP\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
          return 0
          ;;
        RATE_LIMIT)
          if [[ $attempt -le $MAX_RETRIES ]]; then
            log_warn "[$tag] Rate limited. Waiting ${RATE_LIMIT_WAIT}s..."
            sleep "$RATE_LIMIT_WAIT"
            : > "$log_file"
            continue
          fi
          ;;
        RETRYABLE)
          if [[ $attempt -le $MAX_RETRIES ]]; then
            local wt=$(( RETRY_BASE_WAIT * (2 ** (attempt - 1)) ))
            log_warn "[$tag] Retryable error, backing off ${wt}s..."
            sleep "$wt"
            : > "$log_file"
            continue
          fi
          ;;
      esac
      break
    done

    if [[ "$build_ok" != "true" ]]; then
      elapsed=$(( $(date +%s) - t0 ))
      log_error "[$tag] Build FAILED (${elapsed}s, $attempt attempts)"
      printf 'BUILD_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
      return 0
    fi

    # Verify
    if [[ "$VERIFY_IMAGES" == "1" ]]; then
      log_info "[$tag] Verifying SSH..."
      if ! "$VERIFY_SCRIPT" "$tag" "$ROOT_PASSWORD" >> "$log_file" 2>&1; then
        elapsed=$(( $(date +%s) - t0 ))
        log_warn "[$tag] Verify failed (${elapsed}s)"
        printf 'VERIFY_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
        return 0
      fi
    fi

    # Push
    if [[ "$PUSH_IMAGES" == "1" ]]; then
      local detain_tag=""
      if detain_tag="$(derive_detain_tag "$tag")"; then
        log_info "[$tag] Tagging as $detain_tag..."
        if ! docker tag "$tag" "$detain_tag" >> "$log_file" 2>&1; then
          elapsed=$(( $(date +%s) - t0 ))
          log_error "[$tag] Tag failed -> $detain_tag (${elapsed}s)"
          printf 'TAG_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
          return 0
        fi
        log_info "[$detain_tag] Pushing..."
        if ! docker push "$detain_tag" >> "$log_file" 2>&1; then
          elapsed=$(( $(date +%s) - t0 ))
          log_error "[$detain_tag] Push failed (${elapsed}s)"
          printf 'PUSH_FAIL\t%s\t%s\n' "$base" "$detain_tag" > "$RESULTS_DIR/${safe_name}.fail"
          return 0
        fi
      else
        elapsed=$(( $(date +%s) - t0 ))
        log_error "[$tag] Could not derive detain tag (${elapsed}s)"
        printf 'TAG_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
        return 0
      fi
    fi

    elapsed=$(( $(date +%s) - t0 ))
    log_ok "[$tag] Completed (${elapsed}s)"
    touch "$RESULTS_DIR/${safe_name}.ok"
    return 0
  }
  export -f _xargs_build_one

  build_queue="$WORK_DIR/build-queue.csv"
  awk -F',' '$1=="BUILD"{print $2","$3}' "$plan_file" > "$build_queue"

  cat "$build_queue" | xargs -P "$PARALLELISM" -n 1 -I {} bash -lc '_xargs_build_one "{}"' || true

  # ── Non-tmux final report ──
  log_header "Build Summary"

  ok_count=$(find "$RESULTS_DIR" -maxdepth 1 -name '*.ok' 2>/dev/null | wc -l)
  fail_count=$(find "$RESULTS_DIR" -maxdepth 1 -name '*.fail' 2>/dev/null | wc -l)

  schema1_count=0
  build_fail_count=0
  verify_fail_count=0
  render_fail_count=0
  push_fail_count=0
  for f in "$RESULTS_DIR"/*.fail; do
    [[ -f "$f" ]] || continue
    reason="$(cut -f1 < "$f")"
    case "$reason" in
      SCHEMA1_SKIP)   schema1_count=$((schema1_count + 1)) ;;
      BUILD_FAIL)     build_fail_count=$((build_fail_count + 1)) ;;
      VERIFY_FAIL)    verify_fail_count=$((verify_fail_count + 1)) ;;
      RENDER_FAIL)    render_fail_count=$((render_fail_count + 1)) ;;
      PUSH_FAIL)      push_fail_count=$((push_fail_count + 1)) ;;
    esac
  done
  real_fail_count=$((fail_count - schema1_count))
  total=$((ok_count + fail_count))
  wall_elapsed=$(( $(date +%s) - $(cat "$WORK_DIR/.wall_start") ))

  printf '  %-12s %d   (wall time: %dm %ds)\n' "Total:" "$total" $((wall_elapsed/60)) $((wall_elapsed%60))
  printf '  %s%-12s %d%s\n' "$_G" "Succeeded:" "$ok_count" "$_RST"
  if [[ "$schema1_count" -gt 0 ]]; then
    printf '  %s%-12s %d%s  (deprecated manifest v1)\n' "$_Y" "Skipped:" "$schema1_count" "$_RST"
  fi
  if [[ "$real_fail_count" -gt 0 ]]; then
    printf '  %s%-12s %d%s' "$_R" "Failed:" "$real_fail_count" "$_RST"
    local_parts=()
    [[ $render_fail_count -gt 0 ]] && local_parts+=("render=$render_fail_count")
    [[ $build_fail_count -gt 0 ]]  && local_parts+=("build=$build_fail_count")
    [[ $verify_fail_count -gt 0 ]] && local_parts+=("verify=$verify_fail_count")
    [[ $push_fail_count -gt 0 ]]   && local_parts+=("push=$push_fail_count")
    if [[ ${#local_parts[@]} -gt 0 ]]; then
      printf '  (%s)' "$(IFS=', '; echo "${local_parts[*]}")"
    fi
    printf '\n'
  fi
  echo ""

  # ── Good / Bad lists ──
  log_header "Good Templates"
  : > "$WORK_DIR/good_templates.txt"
  for f in "$RESULTS_DIR"/*.ok; do
    [[ -f "$f" ]] || continue
    name="$(basename "$f" .ok)"
    printf '  %s%s%s\n' "$_G" "$name" "$_RST"
    echo "$name" >> "$WORK_DIR/good_templates.txt"
  done

  if [[ "$fail_count" -gt 0 ]]; then
    log_header "Bad Templates"
    : > "$WORK_DIR/bad_templates.txt"
    for f in "$RESULTS_DIR"/*.fail; do
      [[ -f "$f" ]] || continue
      name="$(basename "$f" .fail)"
      reason="$(cut -f1 < "$f" 2>/dev/null || echo "UNKNOWN")"
      printf '  %s%-14s%s %s\n' "$_R" "$reason" "$_RST" "$name"
      echo "$reason $name" >> "$WORK_DIR/bad_templates.txt"
    done
  fi

  # ── Error aggregation ──
  log_header "Errors Grouped by Type"
  declare -A error_files_map=()
  for f in "$RESULTS_DIR"/*.fail; do
    [[ -f "$f" ]] || continue
    name="$(basename "$f" .fail)"
    local_log="$LOG_DIR/${name}.log"
    [[ -f "$local_log" ]] || continue

    while IFS= read -r errline; do
      [[ -z "$errline" ]] && continue
      normalized="$(echo "$errline" | sed \
        -e 's/[0-9]\{4\}-[0-9]\{2\}-[0-9]\{2\}T[0-9:.]*Z\?//g' \
        -e 's/sha256:[a-f0-9]\{64\}/sha256:HASH/g' \
        -e 's|/tmp/[^ ]*|/tmp/PATH|g' \
        -e 's/[0-9]\{1,3\}\.[0-9]\{1,3\}\.[0-9]\{1,3\}\.[0-9]\{1,3\}/IP/g' \
        -e 's/[[:space:]]\+/ /g' -e 's/^ //' -e 's/ $//' \
      )"
      [[ -z "$normalized" ]] && continue
      key="${normalized:0:120}"

      if [[ -n "${error_files_map[$key]+x}" ]]; then
        error_files_map[$key]="${error_files_map[$key]},$name"
      else
        error_files_map[$key]="$name"
      fi
    done < <(grep -iE 'error|fail|fatal|denied|refused|timeout|cannot|unable' "$local_log" 2>/dev/null | tail -5)
  done

  err_idx=0
  for key in "${!error_files_map[@]}"; do
    err_idx=$((err_idx + 1))
    files="${error_files_map[$key]}"
    file_count="$(echo "$files" | tr ',' '\n' | sort -u | wc -l)"
    printf '\n  %s[Error %d]%s (%d template(s))\n' "$_R" "$err_idx" "$_RST" "$file_count"
    printf '  %s%s%s\n' "$_DIM" "$key" "$_RST"
    printf '  Templates: '
    echo "$files" | tr ',' '\n' | sort -u | while IFS= read -r fn; do
      printf '%s ' "$fn"
    done
    echo ""
  done
  [[ $err_idx -eq 0 ]] && printf '  %s(no errors captured)%s\n' "$_DIM" "$_RST"

  echo ""
  log_info "Good list:    $WORK_DIR/good_templates.txt"
  log_info "Bad list:     $WORK_DIR/bad_templates.txt"
  log_info "Build logs:   $LOG_DIR/"

  if [[ "$real_fail_count" -gt 0 ]]; then
    exit 1
  fi
fi
