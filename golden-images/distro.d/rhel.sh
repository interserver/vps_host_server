#!/usr/bin/env bash
# distro.d/rhel.sh — RHEL family plugin
# Covers: oraclelinux, almalinux, rockylinux, centos, sl (Scientific Linux), amazonlinux
PLUGIN_FAMILIES="oraclelinux almalinux rockylinux centos sl amazonlinux"
PLUGIN_SSH_TYPE="openssh"

plugin_fix_repos() {
  local version="$1"
  cat <<'SHELL'
  if [ -f /etc/os-release ]; then . /etc/os-release; fi; \
  (dnf clean all || yum clean all || true) 2>/dev/null; \
  case "${ID:-}" in \
    centos) \
      cent_ver="${VERSION_ID%%.*}"; \
      if [ "${cent_ver:-0}" -eq 8 ] 2>/dev/null; then \
        sed -i 's|^mirrorlist=|#mirrorlist=|g; s|^#baseurl=http://mirror.centos.org|baseurl=http://vault.centos.org|g' /etc/yum.repos.d/CentOS-* 2>/dev/null || true; \
      fi; \
      ;; \
    scientific) \
      sl_ver="${VERSION_ID%%.*}"; \
      sed -i 's|^mirrorlist=|#mirrorlist=|g' /etc/yum.repos.d/sl*.repo 2>/dev/null || true; \
      sed -i 's|^#baseurl=http://ftp.scientificlinux.org/linux|baseurl=http://ftp.scientificlinux.org/linux|g' /etc/yum.repos.d/sl*.repo 2>/dev/null || true; \
      ;; \
    ol) \
      ol_ver="${VERSION_ID%%.*}"; \
      if [ "${ol_ver:-0}" -le 7 ] 2>/dev/null; then \
        yum-config-manager --enable "ol${ol_ver}_latest" 2>/dev/null || true; \
      fi; \
      ;; \
    amzn) \
      if [ -f /etc/yum.repos.d/amzn2-core.repo ]; then \
        sed -i 's|^enabled=0|enabled=1|' /etc/yum.repos.d/amzn2-core.repo 2>/dev/null || true; \
      fi; \
      ;; \
  esac; \
SHELL
}

plugin_install_ssh() {
  local version="$1"
  cat <<'SHELL'
  (dnf install -y openssh-server shadow-utils passwd || yum install -y openssh-server shadow-utils passwd); \
  (dnf clean all || yum clean all || true); \
SHELL
}
