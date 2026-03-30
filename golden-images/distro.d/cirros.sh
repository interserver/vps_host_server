#!/usr/bin/env bash
# distro.d/cirros.sh — CirrOS plugin (uses built-in dropbear)
PLUGIN_FAMILIES="cirros"
PLUGIN_SSH_TYPE="dropbear"

plugin_fix_repos() {
  local version="$1"
  # CirrOS is a minimal cloud image; no package manager repos to fix
  echo '  true; \'
}

plugin_install_ssh() {
  local version="$1"
  # CirrOS ships with dropbear; just ensure dirs exist
  cat <<'SHELL'
  mkdir -p /etc/dropbear /var/run; \
  if ! command -v dropbearkey >/dev/null 2>&1; then \
    echo "WARN: dropbearkey not found in cirros image" >&2; \
  fi; \
SHELL
}
