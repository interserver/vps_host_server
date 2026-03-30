#!/usr/bin/env bash
# lib/queue.sh — File-based atomic job queue

gb_queue_init() {
  mkdir -p "$GB_WORK_DIR" "$GB_RESULTS_DIR" "$GB_LOG_DIR" \
    "$GB_QUEUE_DIR/pending" "$GB_QUEUE_DIR/active" "$GB_QUEUE_DIR/done" "$GB_QUEUE_DIR/failed" \
    "$GB_STATUS_DIR" "$GB_ERRORS_DIR" "$GB_TOKENS_DIR"
  rm -f "$GB_RESULTS_DIR"/*.ok "$GB_RESULTS_DIR"/*.fail 2>/dev/null || true
  rm -f "$GB_STATUS_DIR"/worker-* 2>/dev/null || true
  rm -f "$GB_ERRORS_DIR"/*.err 2>/dev/null || true
  rm -f "$GB_WORK_DIR"/good_templates.txt "$GB_WORK_DIR"/bad_templates.txt 2>/dev/null || true
}

# Parse matrix file → populate queue. Returns total job count.
gb_queue_populate() {
  local matrix_file="$1"
  local job_id=0

  while IFS= read -r line || [[ -n "$line" ]]; do
    # Strip comments and whitespace
    line="${line%%#*}"
    line="$(echo "$line" | tr -d '\r' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
    [[ -z "$line" ]] && continue

    # Format: "family: v1,v2,v3,"
    if [[ "$line" == *":"* && "$line" == *,* && "$line" != *,*:* ]]; then
      local family versions
      family="${line%%:*}"
      family="$(echo "$family" | xargs)"
      versions="${line#*:}"

      IFS=',' read -r -a v_arr <<< "$versions"
      for version in "${v_arr[@]}"; do
        version="$(echo "$version" | xargs)"
        [[ -z "$version" ]] && continue
        job_id=$((job_id + 1))
        local base="$family:$version"
        local tag="${GB_REGISTRY_PREFIX}/${family}-${version}-ssh"
        _queue_write_job "$job_id" "$base" "$tag"
      done
      continue
    fi

    # Format: "base_image" or "base_image,golden_tag"
    IFS=',' read -r col1 col2 <<< "$line"
    col1="$(echo "${col1:-}" | xargs)"
    col2="$(echo "${col2:-}" | xargs)"
    [[ -z "$col1" ]] && continue

    job_id=$((job_id + 1))
    local base="$col1"
    local tag="${col2:-${GB_REGISTRY_PREFIX}/${col1/:/-}-ssh}"
    _queue_write_job "$job_id" "$base" "$tag"
  done < "$matrix_file"

  echo "$job_id"
}

_queue_write_job() {
  local id="$1" base="$2" tag="$3"
  local safe
  safe="$(gb_safe_name "$tag")"
  printf 'base="%s"\ntag="%s"\n' "$base" "$tag" \
    > "$GB_QUEUE_DIR/pending/$(printf '%04d' "$id")-${safe}"
}

# Atomically claim next pending job. Prints job file path or returns 1.
gb_queue_claim() {
  for jf in "$GB_QUEUE_DIR/pending/"*; do
    [[ -f "$jf" ]] || continue
    local jname
    jname="$(basename "$jf")"
    if mv "$jf" "$GB_QUEUE_DIR/active/${jname}" 2>/dev/null; then
      echo "$GB_QUEUE_DIR/active/${jname}"
      return 0
    fi
  done
  return 1
}

# Move job to done/success
gb_queue_done() {
  local job_file="$1"
  mv "$job_file" "$GB_QUEUE_DIR/done/$(basename "$job_file")" 2>/dev/null || true
}

# Move job to failed
gb_queue_fail() {
  local job_file="$1"
  mv "$job_file" "$GB_QUEUE_DIR/failed/$(basename "$job_file")" 2>/dev/null || true
}

# Count jobs in various states
gb_queue_count_pending()  { find "$GB_QUEUE_DIR/pending" -maxdepth 1 -type f 2>/dev/null | wc -l; }
gb_queue_count_active()   { find "$GB_QUEUE_DIR/active"  -maxdepth 1 -type f 2>/dev/null | wc -l; }
gb_queue_count_done()     { find "$GB_QUEUE_DIR/done"    -maxdepth 1 -type f 2>/dev/null | wc -l; }
gb_queue_count_failed()   { find "$GB_QUEUE_DIR/failed"  -maxdepth 1 -type f 2>/dev/null | wc -l; }
gb_queue_count_ok()       { find "$GB_RESULTS_DIR" -maxdepth 1 -name '*.ok'   2>/dev/null | wc -l; }
gb_queue_count_fail()     { find "$GB_RESULTS_DIR" -maxdepth 1 -name '*.fail' 2>/dev/null | wc -l; }
