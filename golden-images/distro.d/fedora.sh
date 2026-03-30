#!/usr/bin/env bash
# distro.d/fedora.sh — Fedora plugin
PLUGIN_FAMILIES="fedora"
PLUGIN_SSH_TYPE="openssh"

plugin_fix_repos() {
  local version="$1"
  cat <<'SHELL'
  if [ -f /etc/os-release ]; then . /etc/os-release; fi; \
  fed_ver="${VERSION_ID:-0}"; \
  if [ "$fed_ver" -le 39 ] 2>/dev/null; then \
    sed -i \
      -e 's|^metalink=|#metalink=|g' \
      -e 's|^#baseurl=http://download.example/pub/fedora/linux|baseurl=https://archives.fedoraproject.org/pub/archive/fedora/linux|g' \
      /etc/yum.repos.d/fedora*.repo 2>/dev/null || true; \
  fi; \
SHELL
}

plugin_install_ssh() {
  local version="$1"
  cat <<'SHELL'
  dnf install -y openssh-server shadow-utils passwd \
    || dnf install -y --allowerasing openssh-server shadow-utils passwd; \
  dnf clean all; \
SHELL
}
