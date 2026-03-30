#!/usr/bin/env bash
# distro.d/debian.sh — Debian plugin
PLUGIN_FAMILIES="debian"
PLUGIN_SSH_TYPE="openssh"

plugin_fix_repos() {
  local version="$1"
  cat <<'SHELL'
  if [ -f /etc/os-release ]; then . /etc/os-release; fi; \
  deb_ver="${VERSION_ID%%.*}"; \
  if [ "${deb_ver:-0}" -le 10 ] 2>/dev/null; then \
    if [ -f /etc/apt/sources.list ]; then \
      sed -i 's|deb.debian.org|archive.debian.org|g; s|security.debian.org/debian-security|archive.debian.org/debian-security|g; s|security.debian.org|archive.debian.org|g' /etc/apt/sources.list; \
      sed -i '/stretch-updates/d; /buster-updates/d; /jessie-updates/d' /etc/apt/sources.list 2>/dev/null || true; \
    fi; \
    for f in /etc/apt/sources.list.d/*.list; do [ -f "$f" ] && sed -i 's|deb.debian.org|archive.debian.org|g' "$f" 2>/dev/null || true; done; \
    echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until; \
  fi; \
  apt-get update 2>/dev/null \
    || { sed -i 's|deb.debian.org|archive.debian.org|g; s|security.debian.org|archive.debian.org|g' /etc/apt/sources.list 2>/dev/null || true; \
         echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until; \
         apt-get update; }; \
SHELL
}

plugin_install_ssh() {
  local version="$1"
  cat <<'SHELL'
  apt_install="apt-get install -y --no-install-recommends"; \
  if [ -f /etc/os-release ]; then . /etc/os-release; fi; \
  deb_ver="${VERSION_ID%%.*}"; \
  if [ "${deb_ver:-0}" -le 8 ] 2>/dev/null; then \
    apt_install="$apt_install --force-yes"; \
  elif [ "${deb_ver:-0}" -le 10 ] 2>/dev/null; then \
    apt_install="$apt_install --allow-unauthenticated"; \
  fi; \
  $apt_install openssh-server passwd ca-certificates; \
  rm -rf /var/lib/apt/lists/*; \
SHELL
}
