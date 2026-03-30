#!/usr/bin/env bash
# distro.d/photon.sh — VMware Photon OS plugin
PLUGIN_FAMILIES="photon"
PLUGIN_SSH_TYPE="openssh"

plugin_fix_repos() {
  local version="$1"
  # Photon repos are generally stable; no fixes needed
  echo '  true; \'
}

plugin_install_ssh() {
  local version="$1"
  cat <<'SHELL'
  tdnf install -y openssh shadow; \
  tdnf clean all; \
SHELL
}
