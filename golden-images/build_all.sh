#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RENDER_SCRIPT="$ROOT_DIR/render_dockerfile.sh"
VERIFY_SCRIPT="$ROOT_DIR/verify_image.sh"

MATRIX_FILE="${1:-$ROOT_DIR/images.matrix}"
ROOT_PASSWORD="${ROOT_PASSWORD:-ChangeMeNow!}"
REGISTRY_PREFIX="${REGISTRY_PREFIX:-provirted}"
PUSH_IMAGES="${PUSH_IMAGES:-0}"
VERIFY_IMAGES="${VERIFY_IMAGES:-1}"
PARALLELISM="${PARALLELISM:-4}"
WORK_DIR="${WORK_DIR:-$ROOT_DIR/build}"

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

  # ── Build ──
  log_info "[$tag] Building image..."
  if ! docker build --pull --network=host \
       --build-arg ROOT_PASSWORD="$ROOT_PASSWORD" \
       -t "$tag" "$out_dir" >> "$log_file" 2>&1; then
    elapsed=$(( $(date +%s) - t0 ))
    log_error "[$tag] Docker build failed after ${elapsed}s (log: $log_file)"
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
export _R _G _Y _B _BOLD _RST
export -f build_one unsupported_family normalize_line_to_pairs
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
total=$((ok_count + fail_count))

printf '  %-12s %d   (wall time: %dm %ds)\n' "Total:" "$total" $((wall_elapsed/60)) $((wall_elapsed%60))
printf '  %s%-12s %d%s\n' "$_G" "Succeeded:" "$ok_count" "$_RST"

if [[ "$fail_count" -gt 0 ]]; then
  printf '  %s%-12s %d%s\n' "$_R" "Failed:" "$fail_count" "$_RST"
  echo ""
  log_error "Failed builds:"

  for f in "$RESULTS_DIR"/*.fail; do
    [[ -f "$f" ]] || continue
    safe_name="$(basename "$f" .fail)"
    IFS=$'\t' read -r reason fbase ftag < "$f"
    printf '  %s%-14s%s %-30s %s\n' "$_R" "$reason" "$_RST" "$fbase" "$ftag"

    # Show last 5 lines of the build log for quick diagnosis
    local_log="$LOG_DIR/${safe_name}.log"
    if [[ -f "$local_log" ]] && [[ -s "$local_log" ]]; then
      printf '  %s── last 5 lines of %s ──%s\n' "$_Y" "${safe_name}.log" "$_RST"
      tail -5 "$local_log" | sed 's/^/    /'
      echo ""
    fi
  done

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

# Exit non-zero if any builds failed
if [[ "$fail_count" -gt 0 ]]; then
  exit 1
fi
