#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 4 ]]; then
  cat <<USAGE
Usage: $0 <base_image> <golden_tag> <root_password> <output_dir>
Example: $0 ubuntu:24.04 provirted/ubuntu-24.04-ssh ChangeMe123 ./build/ubuntu-24.04
USAGE
  exit 1
fi

BASE_IMAGE="$1"
GOLDEN_TAG="$2"
ROOT_PASSWORD="$3"
OUTPUT_DIR="$4"

mkdir -p "$OUTPUT_DIR"

IMAGE_FAMILY="${BASE_IMAGE%%:*}"
IMAGE_TAG="${BASE_IMAGE#*:}"

# ---------------------------------------------------------------------------
# Entrypoint script -- shared by all families that use OpenSSH (not dropbear)
# ---------------------------------------------------------------------------
write_ssh_entrypoint() {
  cat > "$OUTPUT_DIR/provirted-ssh-entrypoint.sh" <<'ENTRYPOINT'
#!/bin/sh
set -eu

if command -v ssh-keygen >/dev/null 2>&1; then
  ssh-keygen -A >/dev/null 2>&1 || true
fi

# sshd re-exec requires an absolute path
SSHD_BIN=$(command -v sshd 2>/dev/null || true)
if [ -x "${SSHD_BIN:-}" ]; then
  "$SSHD_BIN"
elif [ -x /usr/sbin/sshd ]; then
  /usr/sbin/sshd
else
  echo "ERROR: sshd not found in container" >&2
  exit 1
fi

exec "$@"
ENTRYPOINT
  chmod +x "$OUTPUT_DIR/provirted-ssh-entrypoint.sh"
}

# ---------------------------------------------------------------------------
# Entrypoint for dropbear-based images (busybox, cirros)
# ---------------------------------------------------------------------------
write_dropbear_entrypoint() {
  cat > "$OUTPUT_DIR/provirted-ssh-entrypoint.sh" <<'ENTRYPOINT'
#!/bin/sh
set -eu

# CirrOS may have /etc as a broken symlink
if [ -L /etc ] && [ ! -d /etc ]; then rm -f /etc; mkdir -p /etc; fi
mkdir -p /etc/dropbear

# Generate host keys if missing
for kt in rsa ecdsa ed25519; do
  kf="/etc/dropbear/dropbear_${kt}_host_key"
  [ -f "$kf" ] && continue
  dropbearkey -t "$kt" -f "$kf" 2>/dev/null || true
done

# Start dropbear in background — use absolute path
DROPBEAR_BIN=$(command -v dropbear 2>/dev/null || true)
if [ -x "${DROPBEAR_BIN:-}" ]; then
  "$DROPBEAR_BIN" -R -E -F -p 22 &
elif [ -x /usr/sbin/dropbear ]; then
  /usr/sbin/dropbear -R -E -F -p 22 &
else
  echo "ERROR: dropbear not found in container" >&2
  exit 1
fi

exec "$@"
ENTRYPOINT
  chmod +x "$OUTPUT_DIR/provirted-ssh-entrypoint.sh"
}

# ---------------------------------------------------------------------------
# Build metadata
# ---------------------------------------------------------------------------
write_meta() {
  cat > "$OUTPUT_DIR/build-meta.env" <<META
BASE_IMAGE=${BASE_IMAGE}
GOLDEN_TAG=${GOLDEN_TAG}
META
}

# ===========================================================================
# Per-family Dockerfile generators
# ===========================================================================

generate_busybox_dockerfile() {
  write_dropbear_entrypoint
  cat > "$OUTPUT_DIR/Dockerfile" <<DOCKERFILE
# check=skip=SecretsUsedInArgOrEnv
# Multi-stage: grab dropbear + deps from Alpine, copy into busybox
FROM alpine:latest AS ssh-builder
RUN apk add --no-cache dropbear; \
    apk add --no-cache dropbear-dbclient dropbear-scp 2>/dev/null || true; \
    # Auto-stage all shared library deps so COPY never misses one
    mkdir -p /deps/lib /deps/usr/lib; \
    cp /lib/ld-musl-* /deps/lib/; \
    for b in /usr/sbin/dropbear /usr/bin/dropbearkey /usr/bin/dbclient /usr/bin/scp; do \
      [ -f "\$b" ] || continue; \
      ldd "\$b" 2>/dev/null | awk '/=>/{print \$3}' | sort -u | while read -r l; do \
        [ -f "\$l" ] && cp -n "\$l" "/deps\$(dirname "\$l")/" 2>/dev/null || true; \
      done; \
    done

FROM ${BASE_IMAGE}
ARG ROOT_PASSWORD

# Copy dropbear binaries from Alpine
COPY --from=ssh-builder /usr/sbin/dropbear /usr/sbin/dropbear
COPY --from=ssh-builder /usr/bin/dropbearkey /usr/bin/dropbearkey
COPY --from=ssh-builder /usr/bin/dbclient /usr/bin/dbclient
COPY --from=ssh-builder /usr/bin/scp /usr/bin/scp
# All runtime shared library dependencies (auto-detected via ldd)
COPY --from=ssh-builder /deps/lib/ /lib/
COPY --from=ssh-builder /deps/usr/lib/ /usr/lib/

SHELL ["/bin/sh", "-c"]

RUN set -eux; \\
  mkdir -p /etc/dropbear /var/run /root; \\
  dropbearkey -t rsa    -f /etc/dropbear/dropbear_rsa_host_key; \\
  dropbearkey -t ecdsa  -f /etc/dropbear/dropbear_ecdsa_host_key; \\
  echo "root:\${ROOT_PASSWORD}" | chpasswd 2>/dev/null \\
    || { adduser -D -H root 2>/dev/null || true; echo "root:\${ROOT_PASSWORD}" | chpasswd; }

COPY provirted-ssh-entrypoint.sh /usr/local/bin/provirted-ssh-entrypoint.sh
RUN chmod +x /usr/local/bin/provirted-ssh-entrypoint.sh

EXPOSE 22
ENTRYPOINT ["/usr/local/bin/provirted-ssh-entrypoint.sh"]
CMD ["sleep", "infinity"]
DOCKERFILE
}

generate_cirros_dockerfile() {
  write_dropbear_entrypoint
  cat > "$OUTPUT_DIR/Dockerfile" <<DOCKERFILE
# check=skip=SecretsUsedInArgOrEnv
FROM ${BASE_IMAGE}
ARG ROOT_PASSWORD

SHELL ["/bin/sh", "-c"]

# cirros ships with dropbear; just configure it
RUN set -eux; \\
  # CirrOS may have /etc as a broken symlink — ensure it is a real directory \\
  if [ -L /etc ] && [ ! -d /etc ]; then rm -f /etc; mkdir -p /etc; fi; \\
  mkdir -p /etc/dropbear /var/run; \\
  if command -v dropbearkey >/dev/null 2>&1; then \\
    dropbearkey -t rsa    -f /etc/dropbear/dropbear_rsa_host_key 2>/dev/null || true; \\
    dropbearkey -t ecdsa  -f /etc/dropbear/dropbear_ecdsa_host_key 2>/dev/null || true; \\
  fi; \\
  echo "root:\${ROOT_PASSWORD}" | chpasswd 2>/dev/null || true

COPY provirted-ssh-entrypoint.sh /usr/local/bin/provirted-ssh-entrypoint.sh
RUN chmod +x /usr/local/bin/provirted-ssh-entrypoint.sh

EXPOSE 22
ENTRYPOINT ["/usr/local/bin/provirted-ssh-entrypoint.sh"]
CMD ["sleep", "infinity"]
DOCKERFILE
}

generate_standard_dockerfile() {
  write_ssh_entrypoint
  cat > "$OUTPUT_DIR/Dockerfile" <<DOCKERFILE
# check=skip=SecretsUsedInArgOrEnv
FROM ${BASE_IMAGE}

ARG ROOT_PASSWORD
ENV DEBIAN_FRONTEND=noninteractive

SHELL ["/bin/sh", "-c"]

RUN set -eux; \\
  # ── DNS fix ── ensure resolution works inside build (tolerate read-only mount in BuildKit) \\
  (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 1.1.1.1\n' > /etc/resolv.conf) 2>/dev/null || true; \\
  \\
  if [ -f /etc/os-release ]; then . /etc/os-release; fi; \\
  distro="\${ID:-unknown}"; \\
  case "\$distro" in \\
    ubuntu|debian) \\
      # ── Fix EOL / archive repos for Debian ── \\
      if [ "\$distro" = "debian" ]; then \\
        ver_id="\${VERSION_ID%%.*}"; \\
        if [ "\${ver_id:-0}" -le 10 ] 2>/dev/null; then \\
          if [ -f /etc/apt/sources.list ]; then \\
            sed -i 's|deb.debian.org|archive.debian.org|g; s|security.debian.org/debian-security|archive.debian.org/debian-security|g; s|security.debian.org|archive.debian.org|g' /etc/apt/sources.list; \\
          fi; \\
          for f in /etc/apt/sources.list.d/*.list; do [ -f "\$f" ] && sed -i 's|deb.debian.org|archive.debian.org|g' "\$f" 2>/dev/null || true; done; \\
          echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until; \\
          # Remove stretch-updates / buster-updates (gone from archive) \\
          sed -i '/stretch-updates/d; /buster-updates/d; /jessie-updates/d' /etc/apt/sources.list 2>/dev/null || true; \\
        fi; \\
      fi; \\
      # ── Fix EOL repos for Ubuntu ── \\
      apt-get update 2>/dev/null \\
        || { \\
          if [ "\$distro" = "ubuntu" ]; then \\
            # Switch to old-releases for EOL Ubuntu \\
            if [ -f /etc/apt/sources.list ]; then \\
              sed -i \\
                -e 's|http://archive.ubuntu.com|http://old-releases.ubuntu.com|g' \\
                -e 's|http://security.ubuntu.com|http://old-releases.ubuntu.com|g' \\
                -e 's|http://ports.ubuntu.com|http://old-releases.ubuntu.com|g' \\
                /etc/apt/sources.list; \\
            fi; \\
            # Handle DEB822 format (Ubuntu >= 24.04 uses /etc/apt/sources.list.d/ubuntu.sources) \\
            for f in /etc/apt/sources.list.d/*.sources; do \\
              [ -f "\$f" ] || continue; \\
              sed -i 's|archive.ubuntu.com|old-releases.ubuntu.com|g; s|security.ubuntu.com|old-releases.ubuntu.com|g' "\$f" 2>/dev/null || true; \\
            done; \\
            apt-get update; \\
          else \\
            # Debian: try harder with archive \\
            if [ -f /etc/apt/sources.list ]; then \\
              sed -i 's|deb.debian.org|archive.debian.org|g; s|security.debian.org|archive.debian.org|g' /etc/apt/sources.list; \\
            fi; \\
            echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until; \\
            apt-get update; \\
          fi; \\
        }; \\
      apt_install="apt-get install -y --no-install-recommends"; \\
      if [ "\$distro" = "debian" ]; then \\
        deb_ver="\${VERSION_ID%%.*}"; \\
        if [ "\${deb_ver:-0}" -le 8 ] 2>/dev/null; then \\
          apt_install="\$apt_install --force-yes"; \\
        elif [ "\${deb_ver:-0}" -le 10 ] 2>/dev/null; then \\
          apt_install="\$apt_install --allow-unauthenticated"; \\
        fi; \\
      fi; \\
      \$apt_install openssh-server passwd ca-certificates; \\
      rm -rf /var/lib/apt/lists/*; \\
      ;; \\
    alpine) \\
      # ── Fix old Alpine repos ── \\
      ver_id="\${VERSION_ID:-}"; \\
      major_minor="\${ver_id%.*}"; \\
      if [ -n "\$major_minor" ]; then \\
        case "\$major_minor" in \\
          2.*|3.[0-9]|3.1[0-1]) \\
            # Old Alpine, repos may have moved; try both main and community \\
            printf 'https://dl-cdn.alpinelinux.org/alpine/v%s/main\nhttps://dl-cdn.alpinelinux.org/alpine/v%s/community\n' "\$major_minor" "\$major_minor" > /etc/apk/repositories 2>/dev/null || true; \\
            ;; \\
        esac; \\
      fi; \\
      apk update 2>/dev/null || true; \\
      apk add --no-cache openssh || apk add --no-cache --allow-untrusted openssh; \\
      ;; \\
    fedora) \\
      # ── Fix old Fedora repos (EOL releases) ── \\
      ver_id="\${VERSION_ID:-0}"; \\
      if [ "\$ver_id" -le 39 ] 2>/dev/null; then \\
        # Fedora archives old releases; update repo URLs \\
        sed -i \\
          -e 's|^metalink=|#metalink=|g' \\
          -e "s|^#baseurl=http://download.example/pub/fedora/linux|baseurl=https://archives.fedoraproject.org/pub/archive/fedora/linux|g" \\
          /etc/yum.repos.d/fedora*.repo 2>/dev/null || true; \\
      fi; \\
      dnf install -y openssh-server shadow-utils passwd \\
        || dnf install -y --allowerasing openssh-server shadow-utils passwd; \\
      dnf clean all; \\
      ;; \\
    rocky|almalinux|centos|rhel|ol|amzn|amazon|scientific) \\
      (dnf clean all || yum clean all || true) 2>/dev/null; \\
      # ── Fix CentOS 8 vault repos ── \\
      if [ "\$distro" = "centos" ]; then \\
        cent_ver="\${VERSION_ID%%.*}"; \\
        if [ "\${cent_ver:-0}" -eq 8 ] 2>/dev/null; then \\
          sed -i 's|^mirrorlist=|#mirrorlist=|g; s|^#baseurl=http://mirror.centos.org|baseurl=http://vault.centos.org|g' /etc/yum.repos.d/CentOS-* 2>/dev/null || true; \\
        fi; \\
      fi; \\
      # ── Fix Scientific Linux repos ── \\
      if [ "\$distro" = "scientific" ]; then \\
        sl_ver="\${VERSION_ID%%.*}"; \\
        if [ "\${sl_ver:-0}" -le 7 ] 2>/dev/null; then \\
          sed -i 's|^mirrorlist=|#mirrorlist=|g' /etc/yum.repos.d/sl*.repo 2>/dev/null || true; \\
          sed -i "s|^#baseurl=http://ftp.scientificlinux.org/linux|baseurl=http://ftp.scientificlinux.org/linux|g" /etc/yum.repos.d/sl*.repo 2>/dev/null || true; \\
        fi; \\
      fi; \\
      # ── Fix Oracle Linux repos (ensure ol repo is enabled) ── \\
      if [ "\$distro" = "ol" ]; then \\
        ol_ver="\${VERSION_ID%%.*}"; \\
        if [ "\${ol_ver:-0}" -le 7 ] 2>/dev/null; then \\
          yum-config-manager --enable ol7_latest 2>/dev/null || true; \\
        fi; \\
      fi; \\
      # ── Fix Amazon Linux repos ── \\
      if [ "\$distro" = "amzn" ] && [ -f /etc/yum.repos.d/amzn2-core.repo ]; then \\
        sed -i 's|^enabled=0|enabled=1|' /etc/yum.repos.d/amzn2-core.repo 2>/dev/null || true; \\
      fi; \\
      (dnf install -y openssh-server shadow-utils passwd || yum install -y openssh-server shadow-utils passwd); \\
      (dnf clean all || yum clean all || true); \\
      ;; \\
    arch) \\
      # ── Arch: refresh keyring + update first ── \\
      pacman-key --init 2>/dev/null || true; \\
      pacman-key --populate archlinux 2>/dev/null || true; \\
      pacman -Sy --noconfirm archlinux-keyring 2>/dev/null || true; \\
      pacman -Syu --noconfirm openssh shadow; \\
      pacman -Scc --noconfirm; \\
      ;; \\
    photon) \\
      tdnf install -y openssh shadow; \\
      tdnf clean all; \\
      ;; \\
    mageia) \\
      if command -v dnf >/dev/null 2>&1; then \\
        dnf install -y openssh-server passwd; \\
        dnf clean all; \\
      elif command -v urpmi >/dev/null 2>&1; then \\
        urpmi --no-verify-rpm --auto openssh-server passwd; \\
      else \\
        echo "No supported package manager for mageia in ${BASE_IMAGE}" >&2; exit 20; \\
      fi; \\
      ;; \\
    *) \\
      echo "Unsupported distro ID '\$distro' in ${BASE_IMAGE}" >&2; \\
      exit 20; \\
      ;; \\
  esac; \\
  mkdir -p /var/run/sshd /run/sshd; \\
  touch /etc/ssh/sshd_config; \\
  grep -q '^PermitRootLogin ' /etc/ssh/sshd_config \\
    && sed -i 's/^PermitRootLogin .*/PermitRootLogin yes/' /etc/ssh/sshd_config \\
    || echo 'PermitRootLogin yes' >> /etc/ssh/sshd_config; \\
  grep -q '^PasswordAuthentication ' /etc/ssh/sshd_config \\
    && sed -i 's/^PasswordAuthentication .*/PasswordAuthentication yes/' /etc/ssh/sshd_config \\
    || echo 'PasswordAuthentication yes' >> /etc/ssh/sshd_config; \\
  # Disable PAM on distros where it interferes with password auth in containers \\
  if grep -q '^UsePAM ' /etc/ssh/sshd_config 2>/dev/null; then \\
    sed -i 's/^UsePAM .*/UsePAM no/' /etc/ssh/sshd_config; \\
  fi; \\
  echo "root:\${ROOT_PASSWORD}" | chpasswd 2>/dev/null \\
    || echo "root:\${ROOT_PASSWORD}" | busybox chpasswd

COPY provirted-ssh-entrypoint.sh /usr/local/bin/provirted-ssh-entrypoint.sh
RUN chmod +x /usr/local/bin/provirted-ssh-entrypoint.sh

EXPOSE 22
ENTRYPOINT ["/usr/local/bin/provirted-ssh-entrypoint.sh"]
CMD ["sleep", "infinity"]
DOCKERFILE
}

# ===========================================================================
# Dispatch to the appropriate generator
# ===========================================================================

case "$IMAGE_FAMILY" in
  busybox)
    generate_busybox_dockerfile
    ;;
  cirros)
    generate_cirros_dockerfile
    ;;
  *)
    generate_standard_dockerfile
    ;;
esac

write_meta

printf 'Prepared build context: %s\n' "$OUTPUT_DIR"
printf 'Image target: %s\n' "$GOLDEN_TAG"
printf 'Build with: docker build --build-arg ROOT_PASSWORD=*** -t %s %s\n' "$GOLDEN_TAG" "$OUTPUT_DIR"

: "$ROOT_PASSWORD"
