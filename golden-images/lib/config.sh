#!/usr/bin/env bash
# lib/config.sh — Configuration loading and defaults

# ── Defaults (can be overridden by config file or env vars) ─────────────────
: "${GB_PARALLELISM:=6}"
: "${GB_ROOT_PASSWORD:=${ROOT_PASSWORD:-InterServer!23}}"
: "${GB_REGISTRY_PREFIX:=${REGISTRY_PREFIX:-interserver}}"
: "${GB_PUSH_IMAGES:=${PUSH_IMAGES:-0}}"
: "${GB_VERIFY_IMAGES:=${VERIFY_IMAGES:-1}}"
: "${GB_MAX_RETRIES:=${MAX_RETRIES:-3}}"
: "${GB_RETRY_BASE_WAIT:=${RETRY_BASE_WAIT:-30}}"
: "${GB_RATE_LIMIT_WAIT:=${RATE_LIMIT_WAIT:-900}}"
: "${GB_MIN_DISK_MB:=${MIN_DISK_MB:-3000}}"
: "${GB_JSON_REPORT:=0}"
: "${GB_CLEAN_FIRST:=0}"
: "${GB_USE_TUI:=auto}"
: "${GB_DEBUG:=0}"

gb_config_load() {
  # Load config file if present
  local conf="${GB_CONFIG_FILE:-$GB_ROOT/golden-build.conf}"
  if [[ -f "$conf" ]]; then
    gb_log_debug "Loading config: $conf"
    # shellcheck disable=SC1090
    source "$conf"
  fi

  # Resolve paths
  : "${GB_MATRIX_FILE:=$GB_ROOT/images.matrix}"
  : "${GB_WORK_DIR:=${WORK_DIR:-$GB_ROOT/build}}"
  export GB_LOG_DIR="$GB_WORK_DIR/logs"
  export GB_RESULTS_DIR="$GB_WORK_DIR/.results"
  export GB_QUEUE_DIR="$GB_WORK_DIR/.queue"
  export GB_STATUS_DIR="$GB_WORK_DIR/.status"
  export GB_ERRORS_DIR="$GB_WORK_DIR/.errors"
  export GB_TOKENS_DIR="$GB_WORK_DIR/.tokens"

  # Export all GB_ variables for workers
  export GB_PARALLELISM GB_ROOT_PASSWORD GB_REGISTRY_PREFIX
  export GB_PUSH_IMAGES GB_VERIFY_IMAGES GB_MAX_RETRIES
  export GB_RETRY_BASE_WAIT GB_RATE_LIMIT_WAIT GB_MIN_DISK_MB
  export GB_JSON_REPORT GB_USE_TUI GB_DEBUG
  export GB_WORK_DIR GB_MATRIX_FILE GB_ROOT GB_SCRIPT
}

# Save runtime config so workers can load it
gb_config_save_runtime() {
  cat > "$GB_WORK_DIR/.runtime.conf" <<EOF
GB_ROOT_PASSWORD='${GB_ROOT_PASSWORD}'
GB_REGISTRY_PREFIX='${GB_REGISTRY_PREFIX}'
GB_PUSH_IMAGES='${GB_PUSH_IMAGES}'
GB_VERIFY_IMAGES='${GB_VERIFY_IMAGES}'
GB_MAX_RETRIES='${GB_MAX_RETRIES}'
GB_RETRY_BASE_WAIT='${GB_RETRY_BASE_WAIT}'
GB_RATE_LIMIT_WAIT='${GB_RATE_LIMIT_WAIT}'
GB_MIN_DISK_MB='${GB_MIN_DISK_MB}'
GB_WORK_DIR='${GB_WORK_DIR}'
GB_LOG_DIR='${GB_LOG_DIR}'
GB_RESULTS_DIR='${GB_RESULTS_DIR}'
GB_QUEUE_DIR='${GB_QUEUE_DIR}'
GB_STATUS_DIR='${GB_STATUS_DIR}'
GB_ERRORS_DIR='${GB_ERRORS_DIR}'
GB_TOKENS_DIR='${GB_TOKENS_DIR}'
GB_ROOT='${GB_ROOT}'
GB_SCRIPT='${GB_SCRIPT}'
EOF
}

# Load runtime config (used by workers)
gb_config_load_runtime() {
  local wd="$1"
  # shellcheck disable=SC1091
  [[ -f "$wd/.runtime.conf" ]] && source "$wd/.runtime.conf"
}
