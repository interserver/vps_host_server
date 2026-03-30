#!/usr/bin/env bash
# lib/report.sh — JSON and text reporting with error aggregation

gb_report_text() {
  local wd="$1"
  local results_dir="$wd/.results"

  local ok_count fail_count
  ok_count=$(find "$results_dir" -maxdepth 1 -name '*.ok' 2>/dev/null | wc -l)
  fail_count=$(find "$results_dir" -maxdepth 1 -name '*.fail' 2>/dev/null | wc -l)

  local schema1=0 build_fail=0 verify_fail=0 render_fail=0 push_fail=0
  for f in "$results_dir"/*.fail; do
    [[ -f "$f" ]] || continue
    local reason
    reason="$(cut -f1 < "$f")"
    case "$reason" in
      SCHEMA1_SKIP) schema1=$((schema1 + 1)) ;;
      BUILD_FAIL)   build_fail=$((build_fail + 1)) ;;
      VERIFY_FAIL)  verify_fail=$((verify_fail + 1)) ;;
      RENDER_FAIL)  render_fail=$((render_fail + 1)) ;;
      PUSH_FAIL)    push_fail=$((push_fail + 1)) ;;
    esac
  done
  local real_fail=$((fail_count - schema1))
  local total=$((ok_count + fail_count))

  local wall_start elapsed=0
  wall_start="$(cat "$wd/.wall_start" 2>/dev/null || echo 0)"
  [[ "$wall_start" -gt 0 ]] && elapsed=$(( $(date +%s) - wall_start ))

  printf '\n  %-14s %d   (wall time: %dm %ds)\n' "Total:" "$total" $((elapsed/60)) $((elapsed%60))
  printf '  %s%-14s %d%s\n' "$GB_G" "Succeeded:" "$ok_count" "$GB_RST"
  [[ $schema1 -gt 0 ]] && printf '  %s%-14s %d%s  (deprecated manifest v1)\n' "$GB_Y" "Skipped:" "$schema1" "$GB_RST"
  if [[ $real_fail -gt 0 ]]; then
    printf '  %s%-14s %d%s' "$GB_R" "Failed:" "$real_fail" "$GB_RST"
    local parts=()
    [[ $render_fail -gt 0 ]] && parts+=("render=$render_fail")
    [[ $build_fail -gt 0 ]]  && parts+=("build=$build_fail")
    [[ $verify_fail -gt 0 ]] && parts+=("verify=$verify_fail")
    [[ $push_fail -gt 0 ]]   && parts+=("push=$push_fail")
    [[ ${#parts[@]} -gt 0 ]] && printf '  (%s)' "$(IFS=', '; echo "${parts[*]}")"
    printf '\n'
  fi

  # Good templates
  gb_log_header "Good Templates"
  : > "$wd/good_templates.txt"
  for f in "$results_dir"/*.ok; do
    [[ -f "$f" ]] || continue
    local name
    name="$(basename "$f" .ok)"
    printf '  %s%s%s\n' "$GB_G" "$name" "$GB_RST"
    echo "$name" >> "$wd/good_templates.txt"
  done

  # Bad templates
  if [[ $fail_count -gt 0 ]]; then
    gb_log_header "Bad Templates"
    : > "$wd/bad_templates.txt"
    for f in "$results_dir"/*.fail; do
      [[ -f "$f" ]] || continue
      local name reason
      name="$(basename "$f" .fail)"
      reason="$(cut -f1 < "$f" 2>/dev/null || echo "UNKNOWN")"
      printf '  %s%-14s%s %s\n' "$GB_R" "$reason" "$GB_RST" "$name"
      echo "$reason $name" >> "$wd/bad_templates.txt"
    done
  fi
}

# Error aggregation — groups unique errors across all builds
gb_report_errors() {
  local wd="$1"
  local errors_dir="$wd/.errors"
  local log_dir="$wd/logs"
  local results_dir="$wd/.results"

  gb_log_header "Errors Grouped by Type"

  # Build error → files mapping
  declare -A err_map=()

  # From .err files (captured during build)
  for ef in "$errors_dir"/*.err; do
    [[ -f "$ef" ]] || continue
    local ename
    ename="$(basename "$ef" .err)"
    while IFS= read -r line; do
      [[ -z "$line" ]] && continue
      local key
      key="$(_normalize_error "$line")"
      [[ -z "$key" ]] && continue
      if [[ -n "${err_map[$key]+x}" ]]; then
        err_map[$key]="${err_map[$key]},$ename"
      else
        err_map[$key]="$ename"
      fi
    done < "$ef"
  done

  # Also scan log files for failed builds without .err files
  for f in "$results_dir"/*.fail; do
    [[ -f "$f" ]] || continue
    local name
    name="$(basename "$f" .fail)"
    [[ -f "$errors_dir/${name}.err" ]] && continue  # Already captured
    local logf="$log_dir/${name}.log"
    [[ -f "$logf" ]] || continue
    local lines
    lines="$(grep -iE 'error|fail|fatal|denied|refused|timeout|cannot|unable' "$logf" 2>/dev/null | tail -3)" || true
    while IFS= read -r line; do
      [[ -z "$line" ]] && continue
      local key
      key="$(_normalize_error "$line")"
      [[ -z "$key" ]] && continue
      if [[ -n "${err_map[$key]+x}" ]]; then
        err_map[$key]="${err_map[$key]},$name"
      else
        err_map[$key]="$name"
      fi
    done <<< "$lines"
  done

  local idx=0
  for key in "${!err_map[@]}"; do
    idx=$((idx + 1))
    local files="${err_map[$key]}"
    local count
    count="$(echo "$files" | tr ',' '\n' | sort -u | wc -l)"
    printf '\n  %s[Error %d]%s (%d template(s))\n' "$GB_R" "$idx" "$GB_RST" "$count"
    printf '  %s%s%s\n' "$GB_DIM" "$key" "$GB_RST"
    printf '  Templates: '
    echo "$files" | tr ',' '\n' | sort -u | tr '\n' ' '
    echo ""
  done

  [[ $idx -eq 0 ]] && printf '  %s(no errors captured)%s\n' "$GB_DIM" "$GB_RST"
  echo ""

  gb_log_info "Good list:    $wd/good_templates.txt"
  gb_log_info "Bad list:     $wd/bad_templates.txt"
  gb_log_info "Build logs:   $log_dir/"
}

# Normalize error messages for deduplication
_normalize_error() {
  echo "$1" | sed \
    -e 's/[0-9]\{4\}-[0-9]\{2\}-[0-9]\{2\}T[0-9:.]*Z\?//g' \
    -e 's/sha256:[a-f0-9]\{64\}/sha256:HASH/g' \
    -e 's|/tmp/[^ ]*|/tmp/PATH|g' \
    -e 's|/var/[^ ]*/[^ ]*|/var/PATH|g' \
    -e 's/[0-9]\{1,3\}\.[0-9]\{1,3\}\.[0-9]\{1,3\}\.[0-9]\{1,3\}/IP/g' \
    -e 's/port [0-9]*/port PORT/g' \
    -e 's/[[:space:]]\+/ /g' \
    -e 's/^ //' -e 's/ $//' \
  | cut -c1-120
}

# Structured JSON report
gb_report_json() {
  local wd="$1"
  local outfile="$wd/report.json"
  local results_dir="$wd/.results"

  local ok_count fail_count total elapsed
  ok_count=$(find "$results_dir" -maxdepth 1 -name '*.ok' 2>/dev/null | wc -l)
  fail_count=$(find "$results_dir" -maxdepth 1 -name '*.fail' 2>/dev/null | wc -l)
  total=$((ok_count + fail_count))
  local wall_start
  wall_start="$(cat "$wd/.wall_start" 2>/dev/null || echo 0)"
  elapsed=0
  [[ "$wall_start" -gt 0 ]] && elapsed=$(( $(date +%s) - wall_start ))

  {
    printf '{\n'
    printf '  "version": %s,\n' "$(gb_json_str "$GB_VERSION")"
    printf '  "generated": %s,\n' "$(gb_json_str "$(date -u +%Y-%m-%dT%H:%M:%SZ)")"
    printf '  "config": {\n'
    printf '    "parallelism": %d,\n' "${GB_PARALLELISM:-6}"
    printf '    "matrix": %s,\n' "$(gb_json_str "${GB_MATRIX_FILE:-}")"
    printf '    "registry_prefix": %s,\n' "$(gb_json_str "${GB_REGISTRY_PREFIX:-}")"
    printf '    "verify": %s\n' "${GB_VERIFY_IMAGES:-1}"
    printf '  },\n'
    printf '  "summary": {\n'
    printf '    "total": %d,\n' "$total"
    printf '    "succeeded": %d,\n' "$ok_count"
    printf '    "failed": %d,\n' "$fail_count"
    printf '    "elapsed_seconds": %d\n' "$elapsed"
    printf '  },\n'

    # Succeeded array
    printf '  "succeeded": [\n'
    local first=true
    for f in "$results_dir"/*.ok; do
      [[ -f "$f" ]] || continue
      [[ "$first" == "true" ]] || printf ',\n'
      first=false
      printf '    { "name": %s }' "$(gb_json_str "$(basename "$f" .ok)")"
    done
    printf '\n  ],\n'

    # Failed array
    printf '  "failed": [\n'
    first=true
    for f in "$results_dir"/*.fail; do
      [[ -f "$f" ]] || continue
      [[ "$first" == "true" ]] || printf ',\n'
      first=false
      local reason fbase ftag
      IFS=$'\t' read -r reason fbase ftag < "$f"
      printf '    { "name": %s, "reason": %s, "base": %s, "tag": %s }' \
        "$(gb_json_str "$(basename "$f" .fail)")" \
        "$(gb_json_str "$reason")" \
        "$(gb_json_str "$fbase")" \
        "$(gb_json_str "${ftag:-}")"
    done
    printf '\n  ]\n'
    printf '}\n'
  } > "$outfile"
}
