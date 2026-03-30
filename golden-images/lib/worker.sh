#!/usr/bin/env bash
# lib/worker.sh — Build worker (runs as background process or xargs child)

gb_worker_run() {
  local wid="$1" wd="$2"
  gb_config_load_runtime "$wd"

  local status_file="$GB_STATUS_DIR/worker-${wid}"
  local built_ok=0 built_fail=0

  _wstatus() { echo "$*" > "$status_file"; }
  _wstatus "idle"
  _wlog() { printf '%s[W%s %s]%s %s\n' "$GB_C" "$wid" "$(_gb_ts)" "$GB_RST" "$*"; }

  while true; do
    local job_file
    job_file="$(gb_queue_claim)" || break

    # Read job
    local base="" tag=""
    # shellcheck disable=SC1090
    source "$job_file"
    local safe_name
    safe_name="$(gb_safe_name "$tag")"

    _wstatus "building $base"
    _wlog "Build: $base -> $tag"

    local out_dir="$GB_WORK_DIR/$safe_name"
    local log_file="$GB_LOG_DIR/${safe_name}.log"
    local t0 elapsed
    t0=$(date +%s)
    : > "$log_file"

    # Disk space check
    gb_disk_ensure

    # ── Generate Dockerfile ──
    if ! gb_dockerfile_generate "$base" "$tag" "$out_dir" >> "$log_file" 2>&1; then
      elapsed=$(( $(date +%s) - t0 ))
      _wlog "RENDER FAIL: $base (${elapsed}s)"
      printf 'RENDER_FAIL\t%s\t%s\n' "$base" "$tag" > "$GB_RESULTS_DIR/${safe_name}.fail"
      _worker_record_error "$log_file" "$safe_name"
      gb_queue_fail "$job_file"
      built_fail=$((built_fail + 1))
      continue
    fi

    # ── Build with retry ──
    local attempt=0 build_ok=false
    while true; do
      attempt=$((attempt + 1))
      _wstatus "building $base (attempt $attempt)"

      if docker build --pull --network=host --dns 8.8.8.8 \
           --build-arg ROOT_PASSWORD="$GB_ROOT_PASSWORD" \
           -t "$tag" "$out_dir" >> "$log_file" 2>&1; then
        build_ok=true
        break
      fi

      local err_class
      err_class="$(gb_classify_error "$log_file")"

      case "$err_class" in
        SCHEMA1)
          _wlog "SCHEMA1 SKIP: $base"
          printf 'SCHEMA1_SKIP\t%s\t%s\n' "$base" "$tag" > "$GB_RESULTS_DIR/${safe_name}.fail"
          gb_queue_fail "$job_file"
          built_fail=$((built_fail + 1))
          continue 2
          ;;
        RATE_LIMIT)
          if [[ $attempt -le $GB_MAX_RETRIES ]]; then
            local rl_wait
            rl_wait="$(gb_detect_rate_wait "$log_file")"
            _wstatus "rate-limited (${rl_wait}s)"
            _wlog "Rate limited. Waiting ${rl_wait}s..."
            sleep "$rl_wait"
            : > "$log_file"
            continue
          fi
          ;;
        RETRYABLE)
          if [[ $attempt -le $GB_MAX_RETRIES ]]; then
            local wait_time=$(( GB_RETRY_BASE_WAIT * (2 ** (attempt - 1)) ))
            _wstatus "retry-wait (${wait_time}s)"
            _wlog "Retryable error, backing off ${wait_time}s..."
            sleep "$wait_time"
            : > "$log_file"
            continue
          fi
          ;;
      esac
      break
    done

    if [[ "$build_ok" != "true" ]]; then
      elapsed=$(( $(date +%s) - t0 ))
      _wlog "BUILD FAIL: $base (${elapsed}s, $attempt attempts)"
      printf 'BUILD_FAIL\t%s\t%s\n' "$base" "$tag" > "$GB_RESULTS_DIR/${safe_name}.fail"
      _worker_record_error "$log_file" "$safe_name"
      gb_queue_fail "$job_file"
      built_fail=$((built_fail + 1))
      continue
    fi

    # ── Verify SSH ──
    if [[ "$GB_VERIFY_IMAGES" == "1" ]]; then
      _wstatus "verifying $tag"
      _wlog "Verify: $tag"
      if ! gb_verify_image "$tag" "$GB_ROOT_PASSWORD" >> "$log_file" 2>&1; then
        elapsed=$(( $(date +%s) - t0 ))
        _wlog "VERIFY FAIL: $tag (${elapsed}s)"
        printf 'VERIFY_FAIL\t%s\t%s\n' "$base" "$tag" > "$GB_RESULTS_DIR/${safe_name}.fail"
        _worker_record_error "$log_file" "$safe_name"
        gb_queue_fail "$job_file"
        built_fail=$((built_fail + 1))
        continue
      fi
    fi

    # ── Push ──
    if [[ "$GB_PUSH_IMAGES" == "1" ]]; then
      _wstatus "pushing $tag"
      if ! docker push "$tag" >> "$log_file" 2>&1; then
        elapsed=$(( $(date +%s) - t0 ))
        _wlog "PUSH FAIL: $tag (${elapsed}s)"
        printf 'PUSH_FAIL\t%s\t%s\n' "$base" "$tag" > "$GB_RESULTS_DIR/${safe_name}.fail"
        gb_queue_fail "$job_file"
        built_fail=$((built_fail + 1))
        continue
      fi
    fi

    # ── Success ──
    elapsed=$(( $(date +%s) - t0 ))
    _wlog "OK: $tag (${elapsed}s)"
    touch "$GB_RESULTS_DIR/${safe_name}.ok"
    gb_queue_done "$job_file"
    built_ok=$((built_ok + 1))
  done

  _wstatus "finished ok=$built_ok fail=$built_fail"
  _wlog "Done. ok=$built_ok fail=$built_fail"
}

_worker_record_error() {
  local log_file="$1" safe_name="$2"
  local lines
  lines="$(grep -iE 'error|fail|fatal|denied|refused|timeout|cannot|unable' \
    "$log_file" 2>/dev/null | tail -5)" || true
  [[ -n "$lines" ]] && echo "$lines" > "$GB_ERRORS_DIR/${safe_name}.err"
}

# Headless mode: run all builds via xargs (fallback when no TUI)
gb_worker_run_headless() {
  local wd="$1"

  # Build queue list
  local queue_list="$GB_WORK_DIR/.queue_list"
  : > "$queue_list"
  for jf in "$GB_QUEUE_DIR/pending/"*; do
    [[ -f "$jf" ]] || continue
    echo "$jf" >> "$queue_list"
  done

  # Launch workers
  local pids=()
  for ((i = 1; i <= GB_PARALLELISM; i++)); do
    gb_worker_run "$i" "$wd" &
    pids+=($!)
  done

  # Wait with progress
  local total_jobs
  total_jobs="$(cat "$GB_WORK_DIR/.total_jobs" 2>/dev/null || echo 0)"

  while true; do
    local running=0
    for pid in "${pids[@]}"; do
      kill -0 "$pid" 2>/dev/null && running=$((running + 1))
    done
    [[ $running -eq 0 ]] && break

    local ok fail
    ok=$(gb_queue_count_ok)
    fail=$(gb_queue_count_fail)
    printf '\r  Progress: %d/%d (ok=%d fail=%d) workers=%d  ' \
      "$((ok + fail))" "$total_jobs" "$ok" "$fail" "$running"
    sleep 2
  done
  printf '\n'

  for pid in "${pids[@]}"; do
    wait "$pid" 2>/dev/null || true
  done
}
