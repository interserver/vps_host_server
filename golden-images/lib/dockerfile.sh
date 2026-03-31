#!/usr/bin/env bash
# lib/dockerfile.sh — Dockerfile generation using distro plugins

# Find and source the appropriate distro plugin for a given image family.
# Sets PLUGIN_FAMILIES, PLUGIN_SSH_TYPE and makes plugin_* functions available.
gb_dockerfile_load_plugin() {
  local family="$1"
  for plugin in "$GB_ROOT"/distro.d/*.sh; do
    [[ -f "$plugin" ]] || continue
    if grep -qE "PLUGIN_FAMILIES=.*\\b${family}\\b" "$plugin" 2>/dev/null; then
      # shellcheck disable=SC1090
      source "$plugin"
      return 0
    fi
  done
  gb_log_error "No distro plugin for: $family"
  return 1
}

# Generate Dockerfile + entrypoint for a base image
gb_dockerfile_generate() {
  local base="$1" tag="$2" outdir="$3"
  local family="${base%%:*}" version="${base#*:}"

  mkdir -p "$outdir"

  # Load the distro plugin
  gb_dockerfile_load_plugin "$family" || return 1

  local ssh_type="${PLUGIN_SSH_TYPE:-openssh}"

  # Generate entrypoint
  if [[ "$ssh_type" == "dropbear" ]]; then
    _dockerfile_write_dropbear_entrypoint "$outdir"
  else
    _dockerfile_write_ssh_entrypoint "$outdir"
  fi

  # Generate Dockerfile
  {
    echo '# check=skip=SecretsUsedInArgOrEnv'

    # Extra FROM stages (e.g., busybox multi-stage)
    if type plugin_extra_stages &>/dev/null; then
      plugin_extra_stages "$base" "$version"
      echo ""
    fi

    echo "FROM ${base}"
    echo 'ARG ROOT_PASSWORD'
    echo 'ENV DEBIAN_FRONTEND=noninteractive'
    echo 'SHELL ["/bin/sh", "-c"]'
    echo ''
    echo 'RUN set -eux; \'

    # DNS fix — inject working nameservers (tolerate read-only mount in BuildKit)
    echo '  (rm -f /etc/resolv.conf 2>/dev/null; printf '"'"'nameserver 8.8.8.8\nnameserver 1.1.1.1\n'"'"' > /etc/resolv.conf) 2>/dev/null || true; \'

    # Distro-specific: fix repos
    if type plugin_fix_repos &>/dev/null; then
      plugin_fix_repos "$version"
    fi

    # Distro-specific: install SSH
    plugin_install_ssh "$version"

    # SSH configuration
    if [[ "$ssh_type" == "openssh" ]]; then
      cat <<'SSHCONF'
  mkdir -p /var/run/sshd /run/sshd; \
  touch /etc/ssh/sshd_config; \
  grep -q '^PermitRootLogin ' /etc/ssh/sshd_config \
    && sed -i 's/^PermitRootLogin .*/PermitRootLogin yes/' /etc/ssh/sshd_config \
    || echo 'PermitRootLogin yes' >> /etc/ssh/sshd_config; \
  grep -q '^PasswordAuthentication ' /etc/ssh/sshd_config \
    && sed -i 's/^PasswordAuthentication .*/PasswordAuthentication yes/' /etc/ssh/sshd_config \
    || echo 'PasswordAuthentication yes' >> /etc/ssh/sshd_config; \
  if grep -q '^UsePAM ' /etc/ssh/sshd_config 2>/dev/null; then \
    sed -i 's/^UsePAM .*/UsePAM no/' /etc/ssh/sshd_config; \
  fi; \
SSHCONF
    else
      # Dropbear: generate host keys
      cat <<'DBCONF'
  mkdir -p /etc/dropbear /var/run; \
  for kt in rsa ecdsa ed25519; do \
    kf="/etc/dropbear/dropbear_${kt}_host_key"; \
    [ -f "$kf" ] || dropbearkey -t "$kt" -f "$kf" 2>/dev/null || true; \
  done; \
DBCONF
    fi

    # Set root password
    echo '  echo "root:${ROOT_PASSWORD}" | chpasswd 2>/dev/null \'
    echo '    || echo "root:${ROOT_PASSWORD}" | busybox chpasswd 2>/dev/null || true'
    echo ''
    echo 'COPY provirted-ssh-entrypoint.sh /usr/local/bin/provirted-ssh-entrypoint.sh'
    echo 'RUN chmod +x /usr/local/bin/provirted-ssh-entrypoint.sh'
    echo ''
    echo 'EXPOSE 22'
    echo 'ENTRYPOINT ["/usr/local/bin/provirted-ssh-entrypoint.sh"]'
    echo 'CMD ["sleep", "infinity"]'
  } > "$outdir/Dockerfile"

  # Build metadata
  cat > "$outdir/build-meta.env" <<META
BASE_IMAGE=${base}
GOLDEN_TAG=${tag}
FAMILY=${family}
VERSION=${version}
META
}

_dockerfile_write_ssh_entrypoint() {
  cat > "$1/provirted-ssh-entrypoint.sh" <<'ENTRY'
#!/bin/sh
set -eu
if command -v ssh-keygen >/dev/null 2>&1; then
  ssh-keygen -A >/dev/null 2>&1 || true
fi
if command -v sshd >/dev/null 2>&1; then
  sshd
elif [ -x /usr/sbin/sshd ]; then
  /usr/sbin/sshd
else
  echo "ERROR: sshd not found" >&2; exit 1
fi
exec "$@"
ENTRY
  chmod +x "$1/provirted-ssh-entrypoint.sh"
}

_dockerfile_write_dropbear_entrypoint() {
  cat > "$1/provirted-ssh-entrypoint.sh" <<'ENTRY'
#!/bin/sh
set -eu
mkdir -p /etc/dropbear
for kt in rsa ecdsa ed25519; do
  kf="/etc/dropbear/dropbear_${kt}_host_key"
  [ -f "$kf" ] && continue
  dropbearkey -t "$kt" -f "$kf" 2>/dev/null || true
done
if command -v dropbear >/dev/null 2>&1; then
  dropbear -R -E -F -p 22 &
elif [ -x /usr/sbin/dropbear ]; then
  /usr/sbin/dropbear -R -E -F -p 22 &
else
  echo "ERROR: dropbear not found" >&2; exit 1
fi
exec "$@"
ENTRY
  chmod +x "$1/provirted-ssh-entrypoint.sh"
}
