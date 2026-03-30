#!/usr/bin/env bash
# distro.d/arch.sh — Arch Linux plugin
PLUGIN_FAMILIES="archlinux"
PLUGIN_SSH_TYPE="openssh"

plugin_fix_repos() {
  local version="$1"
  cat <<'SHELL'
  pacman-key --init 2>/dev/null || true; \
  pacman-key --populate archlinux 2>/dev/null || true; \
  pacman -Sy --noconfirm archlinux-keyring 2>/dev/null || true; \
SHELL
}

plugin_install_ssh() {
  local version="$1"
  cat <<'SHELL'
  pacman -Syu --noconfirm openssh shadow; \
  pacman -Scc --noconfirm; \
SHELL
}
