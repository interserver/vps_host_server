#!/usr/bin/env bash
# distro.d/ubuntu.sh — Ubuntu plugin
PLUGIN_FAMILIES="ubuntu"
PLUGIN_SSH_TYPE="openssh"

plugin_fix_repos() {
  local version="$1"
  cat <<'SHELL'
  apt-get update 2>/dev/null \
    || { \
      if [ -f /etc/apt/sources.list ]; then \
        sed -i \
          -e 's|http://archive.ubuntu.com|http://old-releases.ubuntu.com|g' \
          -e 's|http://security.ubuntu.com|http://old-releases.ubuntu.com|g' \
          -e 's|http://ports.ubuntu.com|http://old-releases.ubuntu.com|g' \
          /etc/apt/sources.list; \
      fi; \
      for f in /etc/apt/sources.list.d/*.sources; do \
        [ -f "$f" ] || continue; \
        sed -i 's|archive.ubuntu.com|old-releases.ubuntu.com|g; s|security.ubuntu.com|old-releases.ubuntu.com|g' "$f" 2>/dev/null || true; \
      done; \
      apt-get update; \
    }; \
SHELL
}

plugin_install_ssh() {
  local version="$1"
  cat <<'SHELL'
  apt-get install -y --no-install-recommends openssh-server passwd ca-certificates; \
  rm -rf /var/lib/apt/lists/*; \
SHELL
}
