#!/usr/bin/env bash
set -euo pipefail

if [[ $# -lt 2 ]]; then
  cat <<USAGE
Usage: $0 <image_tag> <root_password>
Example: $0 provirted/ubuntu-24.04-ssh ChangeMe123
USAGE
  exit 1
fi

IMAGE_TAG="$1"
ROOT_PASSWORD="$2"

container_id=""
cleanup() {
  if [[ -n "$container_id" ]]; then
    docker rm -f "$container_id" >/dev/null 2>&1 || true
  fi
}
trap cleanup EXIT

# ── Start container ──────────────────────────────────────────────────
run_output="$(docker run -d -P "$IMAGE_TAG" sleep infinity 2>&1)" || {
  echo "ERROR: container failed to start for $IMAGE_TAG" >&2
  echo "$run_output" >&2
  exit 2
}
container_id="$run_output"

# Give the entrypoint a moment to initialise
sleep 2

# Verify container is actually running
container_state="$(docker inspect -f '{{.State.Status}}' "$container_id" 2>/dev/null || echo "unknown")"
if [[ "$container_state" != "running" ]]; then
  echo "ERROR: container not running (state=$container_state) for $IMAGE_TAG" >&2
  echo "── container logs ──" >&2
  docker logs "$container_id" 2>&1 | tail -15 >&2 || true
  exit 2
fi

# ── Get published port ───────────────────────────────────────────────
port=""
for _ in $(seq 1 5); do
  port="$(docker port "$container_id" 22/tcp 2>/dev/null | awk -F: 'NR==1 {print $NF}')" || true
  [[ -n "$port" ]] && break
  sleep 1
done

if [[ -z "$port" ]]; then
  echo "ERROR: no published port for 22/tcp in $IMAGE_TAG" >&2
  echo "Container ports:" >&2
  docker port "$container_id" 2>&1 >&2 || true
  echo "── container logs ──" >&2
  docker logs "$container_id" 2>&1 | tail -15 >&2 || true
  exit 2
fi

# ── Wait for SSH to be ready ─────────────────────────────────────────
ssh_ready=false
for _ in $(seq 1 20); do
  if command -v nc >/dev/null 2>&1 && nc -z 127.0.0.1 "$port" >/dev/null 2>&1; then
    ssh_ready=true
    break
  fi
  sleep 1
done

# ── Verify SSH access ────────────────────────────────────────────────
if command -v sshpass >/dev/null 2>&1 && command -v ssh >/dev/null 2>&1; then
  if ! sshpass -p "$ROOT_PASSWORD" ssh \
      -o StrictHostKeyChecking=no \
      -o UserKnownHostsFile=/dev/null \
      -o ConnectTimeout=10 \
      -p "$port" root@127.0.0.1 \
      'id -u | grep -q "^0$" && echo SSH_OK'; then
    echo "ERROR: SSH login failed for $IMAGE_TAG on port $port" >&2
    echo "── container logs ──" >&2
    docker logs "$container_id" 2>&1 | tail -15 >&2 || true
    exit 3
  fi
else
  echo "WARN: sshpass/ssh not found; using in-container sshd sanity check only." >&2

  # Verify sshd binary exists and is running — portable across minimal images
  exec_output="$(docker exec "$container_id" sh -c '
    # 1. Confirm sshd binary is present
    if ! command -v sshd >/dev/null 2>&1 && ! [ -x /usr/sbin/sshd ]; then
      echo "ERR_NO_SSHD"
      exit 1
    fi

    # 2. Check if sshd process is running (portable: try pgrep → /proc → ps)
    if command -v pgrep >/dev/null 2>&1; then
      pgrep -x sshd >/dev/null && echo "SSHD_RUNNING" && exit 0
    fi
    if [ -d /proc ]; then
      for p in /proc/[0-9]*/comm; do
        if [ -f "$p" ]; then
          read -r pname < "$p" 2>/dev/null || continue
          if [ "$pname" = "sshd" ]; then
            echo "SSHD_RUNNING"
            exit 0
          fi
        fi
      done
    fi
    if command -v ps >/dev/null 2>&1; then
      if ps aux 2>/dev/null | grep -v grep | grep -q '[s]shd'; then
        echo "SSHD_RUNNING"
        exit 0
      fi
    fi

    # 3. As a last resort, check if the sshd pid file exists
    for pidfile in /var/run/sshd.pid /run/sshd.pid; do
      if [ -f "$pidfile" ]; then
        echo "SSHD_PIDFILE"
        exit 0
      fi
    done

    echo "ERR_SSHD_NOT_RUNNING"
    exit 1
  ' 2>&1)" || {
    echo "ERROR: in-container sshd check failed for $IMAGE_TAG" >&2
    echo "$exec_output" >&2
    echo "── container logs ──" >&2
    docker logs "$container_id" 2>&1 | tail -15 >&2 || true
    exit 3
  }

  case "$exec_output" in
    *ERR_NO_SSHD*)
      echo "ERROR: sshd binary not found in $IMAGE_TAG" >&2
      exit 3
      ;;
    *ERR_SSHD_NOT_RUNNING*)
      echo "ERROR: sshd is not running inside $IMAGE_TAG" >&2
      echo "── container logs ──" >&2
      docker logs "$container_id" 2>&1 | tail -15 >&2 || true
      exit 3
      ;;
    *SSHD_RUNNING*|*SSHD_PIDFILE*)
      echo "SSH_OK (basic)"
      ;;
    *)
      echo "WARN: could not confirm sshd status, proceeding anyway" >&2
      echo "SSH_OK (unconfirmed)"
      ;;
  esac
fi

printf 'Verified image: %s\n' "$IMAGE_TAG"
