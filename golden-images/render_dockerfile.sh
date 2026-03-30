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

cat > "$OUTPUT_DIR/provirted-ssh-entrypoint.sh" <<'ENTRYPOINT'
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
  echo "ERROR: sshd not found in container" >&2
  exit 1
fi

exec "$@"
ENTRYPOINT
chmod +x "$OUTPUT_DIR/provirted-ssh-entrypoint.sh"

cat > "$OUTPUT_DIR/Dockerfile" <<DOCKERFILE
FROM ${BASE_IMAGE}

ARG ROOT_PASSWORD
ENV DEBIAN_FRONTEND=noninteractive

SHELL ["/bin/sh", "-c"]

RUN set -eux; \
  if [ -f /etc/os-release ]; then . /etc/os-release; fi; \
  distro="\${ID:-unknown}"; \
  case "\$distro" in \
    ubuntu|debian) \
      apt-get update; \
      apt-get install -y --no-install-recommends openssh-server passwd ca-certificates; \
      rm -rf /var/lib/apt/lists/*; \
      ;; \
    alpine) \
      apk add --no-cache openssh shadow; \
      ;; \
    fedora) \
      dnf install -y openssh-server shadow-utils passwd; \
      dnf clean all; \
      ;; \
    rocky|almalinux|centos|rhel|ol|amzn|amazon) \
      (dnf install -y openssh-server shadow-utils passwd || yum install -y openssh-server shadow-utils passwd); \
      (dnf clean all || yum clean all || true); \
      ;; \
    arch) \
      pacman -Syu --noconfirm openssh shadow; \
      pacman -Scc --noconfirm; \
      ;; \
    photon) \
      tdnf install -y openssh shadow; \
      tdnf clean all; \
      ;; \
    *) \
      echo "Unsupported distro ID '\$distro' in ${BASE_IMAGE}" >&2; \
      exit 20; \
      ;; \
  esac; \
  mkdir -p /var/run/sshd /run/sshd; \
  touch /etc/ssh/sshd_config; \
  grep -q '^PermitRootLogin ' /etc/ssh/sshd_config \
    && sed -i 's/^PermitRootLogin .*/PermitRootLogin yes/' /etc/ssh/sshd_config \
    || echo 'PermitRootLogin yes' >> /etc/ssh/sshd_config; \
  grep -q '^PasswordAuthentication ' /etc/ssh/sshd_config \
    && sed -i 's/^PasswordAuthentication .*/PasswordAuthentication yes/' /etc/ssh/sshd_config \
    || echo 'PasswordAuthentication yes' >> /etc/ssh/sshd_config; \
  echo "root:\${ROOT_PASSWORD}" | chpasswd

COPY provirted-ssh-entrypoint.sh /usr/local/bin/provirted-ssh-entrypoint.sh
RUN chmod +x /usr/local/bin/provirted-ssh-entrypoint.sh

EXPOSE 22
ENTRYPOINT ["/usr/local/bin/provirted-ssh-entrypoint.sh"]
CMD ["sleep", "infinity"]
DOCKERFILE

cat > "$OUTPUT_DIR/build-meta.env" <<META
BASE_IMAGE=${BASE_IMAGE}
GOLDEN_TAG=${GOLDEN_TAG}
META

printf 'Prepared build context: %s\n' "$OUTPUT_DIR"
printf 'Image target: %s\n' "$GOLDEN_TAG"
printf 'Build with: docker build --build-arg ROOT_PASSWORD=*** -t %s %s\n' "$GOLDEN_TAG" "$OUTPUT_DIR"

# keep shellcheck quiet about intentionally accepted but unused vars in generated metadata
: "$ROOT_PASSWORD"
