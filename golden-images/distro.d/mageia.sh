#!/usr/bin/env bash
# distro.d/mageia.sh — Mageia plugin
PLUGIN_FAMILIES="mageia"
PLUGIN_SSH_TYPE="openssh"

plugin_fix_repos() {
  local version="$1"
  # Mageia repos may need mirror updates for old versions
  echo '  true; \'
}

plugin_install_ssh() {
  local version="$1"
  cat <<'SHELL'
  if command -v dnf >/dev/null 2>&1; then \
    dnf install -y openssh-server passwd; \
    dnf clean all; \
  elif command -v urpmi >/dev/null 2>&1; then \
    urpmi --no-verify-rpm --auto openssh-server passwd; \
  else \
    echo "No supported package manager for mageia" >&2; exit 20; \
  fi; \
SHELL
}
