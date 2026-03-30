#!/usr/bin/env bash
# distro.d/alpine.sh — Alpine Linux plugin
PLUGIN_FAMILIES="alpine"
PLUGIN_SSH_TYPE="openssh"

plugin_fix_repos() {
  local version="$1"
  cat <<'SHELL'
  if [ -f /etc/os-release ]; then . /etc/os-release; fi; \
  alp_ver="${VERSION_ID%.*}"; \
  case "${alp_ver:-}" in \
    2.*|3.[0-9]|3.1[0-1]) \
      printf 'https://dl-cdn.alpinelinux.org/alpine/v%s/main\nhttps://dl-cdn.alpinelinux.org/alpine/v%s/community\n' "$alp_ver" "$alp_ver" > /etc/apk/repositories 2>/dev/null || true; \
      ;; \
  esac; \
  apk update 2>/dev/null || true; \
SHELL
}

plugin_install_ssh() {
  local version="$1"
  cat <<'SHELL'
  apk add --no-cache openssh || apk add --no-cache --allow-untrusted openssh; \
SHELL
}
