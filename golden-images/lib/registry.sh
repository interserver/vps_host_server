#!/usr/bin/env bash
# lib/registry.sh — Docker Hub authentication and token management

GB_REGISTRY_AUTHENTICATED=false

# Login to Docker Hub (increases rate limit from 100 to 200 pulls/6h)
gb_registry_login() {
  if [[ -n "${DOCKER_USERNAME:-}" ]] && [[ -n "${DOCKER_PASSWORD:-}" ]]; then
    if echo "$DOCKER_PASSWORD" | docker login -u "$DOCKER_USERNAME" --password-stdin 2>/dev/null; then
      GB_REGISTRY_AUTHENTICATED=true
      gb_log_ok "Docker Hub: authenticated as $DOCKER_USERNAME (200 pulls/6h)"
    else
      gb_log_warn "Docker Hub: login failed, falling back to anonymous"
    fi
  else
    gb_log_info "Docker Hub: anonymous access (100 pulls/6h)"
    gb_log_info "  Tip: export DOCKER_USERNAME/DOCKER_PASSWORD for higher limits"
  fi
}

# Get a bearer token for Docker Hub API (cached per image, 5-min TTL)
gb_registry_token() {
  local image="$1"
  local cache_file="${GB_TOKENS_DIR:-/tmp}/${image//\//__}"

  # Check cache
  if [[ -f "$cache_file" ]]; then
    local age
    age=$(( $(date +%s) - $(stat -c %Y "$cache_file" 2>/dev/null || echo 0) ))
    if [[ $age -lt 300 ]]; then
      cat "$cache_file"
      return 0
    fi
  fi

  local token auth_args=()
  if [[ -n "${DOCKER_USERNAME:-}" ]] && [[ -n "${DOCKER_PASSWORD:-}" ]]; then
    auth_args=(-u "${DOCKER_USERNAME}:${DOCKER_PASSWORD}")
  fi

  token="$(curl -sf "${auth_args[@]}" \
    "https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/${image}:pull" 2>/dev/null)" || return 1

  # Extract token (with or without jq)
  if command -v jq >/dev/null 2>&1; then
    token="$(echo "$token" | jq -r '.token' 2>/dev/null)" || return 1
  else
    token="$(echo "$token" | sed -n 's/.*"token"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p')" || return 1
  fi

  [[ -z "$token" ]] && return 1

  mkdir -p "$(dirname "$cache_file")"
  echo "$token" > "$cache_file"
  echo "$token"
}

# Query Docker Hub rate limit remaining (returns "remaining/limit" or "?/?")
gb_registry_rate_status() {
  local token
  token="$(gb_registry_token "alpine")" || { echo "?/?"; return; }

  local headers
  headers="$(curl -sf -I -H "Authorization: Bearer $token" \
    "https://registry-1.docker.io/v2/library/alpine/manifests/latest" 2>/dev/null)" || { echo "?/?"; return; }

  local remaining limit
  remaining="$(echo "$headers" | grep -oi 'ratelimit-remaining:[^;]*' | head -1 | awk -F: '{print $2}' | tr -dc '0-9')" || true
  limit="$(echo "$headers" | grep -oi 'ratelimit-limit:[^;]*' | head -1 | awk -F: '{print $2}' | tr -dc '0-9')" || true

  echo "${remaining:-?}/${limit:-?}"
}

# Classify build errors — returns: SCHEMA1, RATE_LIMIT, RETRYABLE, FAIL
gb_classify_error() {
  local log_file="$1"

  if grep -qiE 'schema 1 has been removed|manifest version 2, schema 1|v1 manifest|unsupported media type.*schema1' "$log_file" 2>/dev/null; then
    echo "SCHEMA1"
  elif grep -qiE '429|Too Many Requests|rate.limit|toomanyrequests|pull rate limit|TOOMANYREQUESTS' "$log_file" 2>/dev/null; then
    echo "RATE_LIMIT"
  elif grep -qiE 'connection reset|TLS handshake timeout|dial tcp.*timeout|i/o timeout|deadline exceeded|DeadlineExceeded|unexpected EOF|server misbehaving|no such host|network unreachable|connection refused.*registry|net/http.*request canceled|context deadline' "$log_file" 2>/dev/null; then
    echo "RETRYABLE"
  else
    echo "FAIL"
  fi
}

# Parse Retry-After from a log file, or return default wait
gb_detect_rate_wait() {
  local log_file="$1"
  local retry_after
  retry_after="$(grep -oiP 'retry.after[:\s]*\K[0-9]+' "$log_file" 2>/dev/null | head -1)" || true
  if [[ -n "$retry_after" ]] && [[ "$retry_after" -gt 0 ]] 2>/dev/null; then
    echo "$retry_after"
  else
    echo "${GB_RATE_LIMIT_WAIT:-900}"
  fi
}
