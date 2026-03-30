#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RENDER_SCRIPT="$ROOT_DIR/render_dockerfile.sh"
VERIFY_SCRIPT="$ROOT_DIR/verify_image.sh"

MATRIX_FILE="${1:-$ROOT_DIR/images.matrix}"
ROOT_PASSWORD="${ROOT_PASSWORD:-InterServer!23}"
REGISTRY_PREFIX="${REGISTRY_PREFIX:-interserver}"
PUSH_IMAGES="${PUSH_IMAGES:-0}"
VERIFY_IMAGES="${VERIFY_IMAGES:-1}"
PARALLELISM="${PARALLELISM:-8}"
WORK_DIR="${WORK_DIR:-$ROOT_DIR/build}"
MAX_RETRIES="${MAX_RETRIES:-3}"
RETRY_BASE_WAIT="${RETRY_BASE_WAIT:-30}"

# ── Logging ──────────────────────────────────────────────────────────
if [[ -t 1 ]] && [[ -z "${NO_COLOR:-}" ]]; then
  _R=$'\033[0;31m' _G=$'\033[0;32m' _Y=$'\033[0;33m'
  _B=$'\033[0;34m' _BOLD=$'\033[1m' _RST=$'\033[0m'
else
  _R='' _G='' _Y='' _B='' _BOLD='' _RST=''
fi

_ts() { date '+%H:%M:%S'; }
log_info()   { printf '%s[%s INFO]%s  %s\n' "$_B" "$(_ts)" "$_RST" "$*"; }
log_ok()     { printf '%s[%s  OK ]%s  %s\n' "$_G" "$(_ts)" "$_RST" "$*"; }
log_warn()   { printf '%s[%s WARN]%s  %s\n' "$_Y" "$(_ts)" "$_RST" "$*" >&2; }
log_error()  { printf '%s[%s FAIL]%s  %s\n' "$_R" "$(_ts)" "$_RST" "$*" >&2; }
log_header() { printf '\n%s==> %s%s\n' "$_BOLD" "$*" "$_RST"; }

# ── Results tracking ─────────────────────────────────────────────────
RESULTS_DIR="$WORK_DIR/.results"
LOG_DIR="$WORK_DIR/logs"

if [[ ! -f "$MATRIX_FILE" ]]; then
  log_error "Matrix file not found: $MATRIX_FILE"
  exit 1
fi

mkdir -p "$WORK_DIR" "$RESULTS_DIR" "$LOG_DIR"
rm -f "$RESULTS_DIR"/*.ok "$RESULTS_DIR"/*.fail 2>/dev/null || true

# ── Helpers ──────────────────────────────────────────────────────────

unsupported_family() {
  local family="$1"
  case "$family" in
    cirros|sl|busybox)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

normalize_line_to_pairs() {
  # Supported input formats:
  # 1) base_image
  # 2) base_image,golden_tag
  # 3) family: version1,version2,version3,
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
      if unsupported_family "$family"; then
        echo "SKIP,$family:$version,unsupported_family"
        continue
      fi
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

  if unsupported_family "${col1%%:*}"; then
    echo "SKIP,$col1,unsupported_family"
    return 0
  fi

  if [[ -n "$col2" ]]; then
    echo "BUILD,$col1,$col2"
  else
    echo "BUILD,$col1,$REGISTRY_PREFIX/${col1/:/-}-ssh"
  fi
}

# Classify a build log to determine if the error is retryable, a permanent
# skip, or a hard failure.  Prints one of:
#   RETRYABLE   — rate-limit / network transient, worth retrying
#   SCHEMA1     — image uses deprecated manifest schema v1
#   FAIL        — everything else (non-retryable)
classify_build_error() {
  local log_file="$1"
  if grep -qiE 'schema 1 has been removed|manifest version 2, schema 1' "$log_file" 2>/dev/null; then
    echo "SCHEMA1"
  elif grep -qiE '429|Too Many Requests|rate.limit|toomanyrequests|i/o timeout|DeadlineExceeded|connection reset|TLS handshake timeout|unexpected EOF|dial tcp.*timeout|server misbehaving' "$log_file" 2>/dev/null; then
    echo "RETRYABLE"
  else
    echo "FAIL"
  fi
}

build_one() {
  local base="$1"
  local tag="$2"
  local safe_name
  safe_name="$(echo "$tag" | sed 's#[/:]#_#g')"
  local out_dir="$WORK_DIR/$safe_name"
  local log_file="$LOG_DIR/${safe_name}.log"
  local t0 elapsed

  t0=$(date +%s)
  : > "$log_file"

  # ── Render ──
  log_info "[$tag] Rendering Dockerfile from $base"
  if ! "$RENDER_SCRIPT" "$base" "$tag" "$ROOT_PASSWORD" "$out_dir" >> "$log_file" 2>&1; then
    elapsed=$(( $(date +%s) - t0 ))
    log_error "[$tag] Dockerfile render failed after ${elapsed}s (log: $log_file)"
    printf 'RENDER_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
    return 0
  fi

  # ── Build with retry ──
  local attempt=0
  local build_ok=false

  while true; do
    attempt=$((attempt + 1))
    if [[ $attempt -gt 1 ]]; then
      log_info "[$tag] Build attempt $attempt/$((MAX_RETRIES + 1))..."
    else
      log_info "[$tag] Building image..."
    fi

    if docker build --pull --network=host \
         --build-arg ROOT_PASSWORD="$ROOT_PASSWORD" \
         -t "$tag" "$out_dir" >> "$log_file" 2>&1; then
      build_ok=true
      break
    fi

    # ── Classify the error ──
    local err_class
    err_class="$(classify_build_error "$log_file")"

    case "$err_class" in
      SCHEMA1)
        elapsed=$(( $(date +%s) - t0 ))
        log_warn "[$tag] Deprecated image format (schema v1) — skipping"
        printf 'SCHEMA1_SKIP\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
        return 0
        ;;
      RETRYABLE)
        if [[ $attempt -le $MAX_RETRIES ]]; then
          local wait_time=$(( RETRY_BASE_WAIT * (2 ** (attempt - 1)) ))
          log_warn "[$tag] Retryable error (attempt $attempt/$((MAX_RETRIES + 1))), backing off ${wait_time}s..."
          sleep "$wait_time"
          : > "$log_file"
          continue
        fi
        log_error "[$tag] Retryable error persisted after $attempt attempts"
        ;;
    esac

    # Non-retryable or max retries exceeded
    break
  done

  if [[ "$build_ok" != "true" ]]; then
    elapsed=$(( $(date +%s) - t0 ))
    log_error "[$tag] Docker build failed after ${elapsed}s, $attempt attempt(s) (log: $log_file)"
    printf 'BUILD_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
    return 0
  fi

  # ── Verify ──
  if [[ "$VERIFY_IMAGES" == "1" ]]; then
    log_info "[$tag] Verifying SSH access..."
    if ! "$VERIFY_SCRIPT" "$tag" "$ROOT_PASSWORD" >> "$log_file" 2>&1; then
      elapsed=$(( $(date +%s) - t0 ))
      log_warn "[$tag] Verification failed after ${elapsed}s (log: $log_file)"
      printf 'VERIFY_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
      return 0
    fi
  fi

  # ── Push ──
  if [[ "$PUSH_IMAGES" == "1" ]]; then
    log_info "[$tag] Pushing to registry..."
    if ! docker push "$tag" >> "$log_file" 2>&1; then
      elapsed=$(( $(date +%s) - t0 ))
      log_error "[$tag] Push failed after ${elapsed}s (log: $log_file)"
      printf 'PUSH_FAIL\t%s\t%s\n' "$base" "$tag" > "$RESULTS_DIR/${safe_name}.fail"
      return 0
    fi
  fi

  elapsed=$(( $(date +%s) - t0 ))
  log_ok "[$tag] Completed in ${elapsed}s"
  touch "$RESULTS_DIR/${safe_name}.ok"
  return 0
}

export ROOT_DIR RENDER_SCRIPT VERIFY_SCRIPT ROOT_PASSWORD REGISTRY_PREFIX
export PUSH_IMAGES VERIFY_IMAGES WORK_DIR RESULTS_DIR LOG_DIR
export MAX_RETRIES RETRY_BASE_WAIT
export _R _G _Y _B _BOLD _RST
export -f build_one unsupported_family normalize_line_to_pairs classify_build_error
export -f _ts log_info log_ok log_warn log_error log_header

# ── Build plan ───────────────────────────────────────────────────────

plan_file="$WORK_DIR/build-plan.csv"
: > "$plan_file"

while IFS= read -r line || [[ -n "$line" ]]; do
  while IFS= read -r normalized; do
    [[ -z "$normalized" ]] && continue
    echo "$normalized" >> "$plan_file"
  done < <(normalize_line_to_pairs "$line")
done < "$MATRIX_FILE"

build_queue="$WORK_DIR/build-queue.csv"
skip_log="$WORK_DIR/skip-log.csv"
awk -F',' '$1=="BUILD"{print $2","$3}' "$plan_file" > "$build_queue"
awk -F',' '$1=="SKIP"{print $2","$3}' "$plan_file" > "$skip_log" || true

log_header "Build Plan"
total_builds=$(wc -l < "$build_queue")
log_info "Queue: $total_builds image(s), parallelism=$PARALLELISM"
log_info "Retry: up to $MAX_RETRIES retries, base wait ${RETRY_BASE_WAIT}s"
log_info "Logs:  $LOG_DIR/"

if [[ -s "$skip_log" ]]; then
  skip_count=$(wc -l < "$skip_log")
  log_warn "Skipped $skip_count image(s) (unsupported families):"
  while IFS=',' read -r img reason; do
    printf '  %-40s %s\n' "$img" "$reason" >&2
  done < "$skip_log"
fi

if [[ ! -s "$build_queue" ]]; then
  log_warn "Nothing to build."
  exit 0
fi

# ── Execute builds ───────────────────────────────────────────────────

wall_start=$(date +%s)
log_header "Building Images"

# shellcheck disable=SC2016
cat "$build_queue" | xargs -P "$PARALLELISM" -n 1 -I {} bash -lc '
  pair="{}"
  base="${pair%%,*}"
  tag="${pair#*,}"
  build_one "$base" "$tag"
' || true

wall_elapsed=$(( $(date +%s) - wall_start ))

# ── Summary ──────────────────────────────────────────────────────────

log_header "Build Summary"
ok_count=$(find "$RESULTS_DIR" -maxdepth 1 -name '*.ok' 2>/dev/null | wc -l)
fail_count=$(find "$RESULTS_DIR" -maxdepth 1 -name '*.fail' 2>/dev/null | wc -l)

# Break failures into categories
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

printf '  %-12s %d   (wall time: %dm %ds)\n' "Total:" "$total" $((wall_elapsed/60)) $((wall_elapsed%60))
printf '  %s%-12s %d%s\n' "$_G" "Succeeded:" "$ok_count" "$_RST"
if [[ "$schema1_count" -gt 0 ]]; then
  printf '  %s%-12s %d%s  (deprecated manifest v1 — remove from matrix)\n' "$_Y" "Skipped:" "$schema1_count" "$_RST"
fi
if [[ "$real_fail_count" -gt 0 ]]; then
  printf '  %s%-12s %d%s' "$_R" "Failed:" "$real_fail_count" "$_RST"
  # Inline breakdown
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

# ── Detailed failure listing ──
if [[ "$fail_count" -gt 0 ]]; then
  echo ""

  # List schema v1 skips concisely
  if [[ "$schema1_count" -gt 0 ]]; then
    log_warn "Schema v1 images (consider removing from images.matrix):"
    for f in "$RESULTS_DIR"/*.fail; do
      [[ -f "$f" ]] || continue
      IFS=$'\t' read -r reason fbase ftag < "$f"
      [[ "$reason" == "SCHEMA1_SKIP" ]] || continue
      printf '  %s%s%s\n' "$_Y" "$fbase" "$_RST"
    done
    echo ""
  fi

  # List real failures with log tails
  if [[ "$real_fail_count" -gt 0 ]]; then
    log_error "Failed builds:"
    for f in "$RESULTS_DIR"/*.fail; do
      [[ -f "$f" ]] || continue
      safe_name="$(basename "$f" .fail)"
      IFS=$'\t' read -r reason fbase ftag < "$f"
      [[ "$reason" == "SCHEMA1_SKIP" ]] && continue

      printf '  %s%-14s%s %-30s %s\n' "$_R" "$reason" "$_RST" "$fbase" "$ftag"

      # Show last 5 lines of the build log for quick diagnosis
      local_log="$LOG_DIR/${safe_name}.log"
      if [[ -f "$local_log" ]] && [[ -s "$local_log" ]]; then
        printf '  %s── last 5 lines of %s ──%s\n' "$_Y" "${safe_name}.log" "$_RST"
        tail -5 "$local_log" | sed 's/^/    /'
        echo ""
      fi
    done
  fi

  # Write machine-readable failure summary
  fail_summary="$WORK_DIR/failures.csv"
  printf 'reason,base,tag\n' > "$fail_summary"
  for f in "$RESULTS_DIR"/*.fail; do
    [[ -f "$f" ]] || continue
    IFS=$'\t' read -r reason fbase ftag < "$f"
    printf '%s,%s,%s\n' "$reason" "$fbase" "$ftag"
  done >> "$fail_summary"

  log_info "Failure summary: $fail_summary"
  log_info "Build logs:      $LOG_DIR/"
fi

if [[ -s "$skip_log" ]]; then
  log_info "Skip log:        $skip_log"
fi

log_info "Build queue:     $build_queue"

# Exit non-zero if any real (non-schema1) builds failed
if [[ "$real_fail_count" -gt 0 ]]; then
  exit 1
fi
