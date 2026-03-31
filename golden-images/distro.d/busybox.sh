#!/usr/bin/env bash
# distro.d/busybox.sh — Busybox plugin (multi-stage with dropbear from Alpine)
PLUGIN_FAMILIES="busybox"
PLUGIN_SSH_TYPE="dropbear"

plugin_extra_stages() {
  local base="$1" version="$2"
  cat <<'STAGES'
FROM alpine:latest AS ssh-builder
RUN apk add --no-cache dropbear; \
    apk add --no-cache dropbear-dbclient dropbear-scp 2>/dev/null || true

STAGES
}

plugin_fix_repos() {
  local version="$1"
  # Busybox has no package manager; multi-stage handles deps
  echo '  true; \'
}

plugin_install_ssh() {
  local version="$1"
  # Copy dropbear binaries and musl libs from builder stage
  cat <<'SHELL'
  true
# (ssh installed via multi-stage COPY below)

COPY --from=ssh-builder /usr/sbin/dropbear /usr/sbin/dropbear
COPY --from=ssh-builder /usr/bin/dropbearkey /usr/bin/dropbearkey
COPY --from=ssh-builder /usr/bin/dbclient /usr/bin/dbclient
COPY --from=ssh-builder /usr/bin/scp /usr/bin/scp
COPY --from=ssh-builder /lib/ld-musl-* /lib/
COPY --from=ssh-builder /usr/lib/libz.so* /usr/lib/

RUN set -eux; \
SHELL
}
