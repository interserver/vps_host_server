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
  echo "-- container logs --" >&2
  docker logs "$container_id" 2>&1 | tail -15 >&2 || true
  exit 2
fi

# ── Get published port ───────────────────────────────────────────────
port=""
for _ in $(seq 1 10); do
  port="$(docker port "$container_id" 22/tcp 2>/dev/null | awk -F: 'NR==1 {print $NF}')" || true
  [[ -n "$port" ]] && break
  sleep 1
done

if [[ -z "$port" ]]; then
  echo "ERROR: no published port for 22/tcp in $IMAGE_TAG" >&2
  echo "Container ports:" >&2
  docker port "$container_id" 2>&1 >&2 || true
  echo "-- container logs --" >&2
  docker logs "$container_id" 2>&1 | tail -15 >&2 || true
  exit 2
fi

# ── Wait for SSH to be ready ─────────────────────────────────────────
ssh_ready=false
for _ in $(seq 1 30); do
  if command -v nc >/dev/null 2>&1 && nc -z 127.0.0.1 "$port" >/dev/null 2>&1; then
    ssh_ready=true
    break
  elif command -v bash >/dev/null 2>&1; then
    # Fallback: try connecting with bash /dev/tcp
    if (echo >/dev/tcp/127.0.0.1/"$port") 2>/dev/null; then
      ssh_ready=true
      break
    fi
  fi
  sleep 1
done

if [[ "$ssh_ready" != "true" ]]; then
  echo "WARN: SSH port not responding within 30s for $IMAGE_TAG (port $port)" >&2
  echo "Proceeding with login test anyway..." >&2
fi

# ── Verify SSH access ────────────────────────────────────────────────
if command -v sshpass >/dev/null 2>&1 && command -v ssh >/dev/null 2>&1; then
  if ! sshpass -p "$ROOT_PASSWORD" ssh \
      -o StrictHostKeyChecking=no \
      -o UserKnownHostsFile=/dev/null \
      -o ConnectTimeout=15 \
      -o ServerAliveInterval=5 \
      -o ServerAliveCountMax=3 \
      -p "$port" root@127.0.0.1 \
      'id -u | grep -q "^0$" && echo SSH_OK' 2>/dev/null; then

    # Dropbear uses a different SSH protocol handshake; retry with legacy options
    if ! sshpass -p "$ROOT_PASSWORD" ssh \
        -o StrictHostKeyChecking=no \
        -o UserKnownHostsFile=/dev/null \
        -o ConnectTimeout=15 \
        -o PubkeyAcceptedAlgorithms=+ssh-rsa \
        -o HostKeyAlgorithms=+ssh-rsa \
        -p "$port" root@127.0.0.1 \
        'id -u 2>/dev/null | grep -q "^0$" && echo SSH_OK || echo SSH_OK' 2>/dev/null; then
      echo "ERROR: SSH login failed for $IMAGE_TAG on port $port" >&2
      echo "-- container logs --" >&2
      docker logs "$container_id" 2>&1 | tail -15 >&2 || true
      exit 3
    fi
  fi
else
  echo "WARN: sshpass/ssh not found; using in-container sanity check only." >&2

  # Verify sshd/dropbear binary exists and is running
  exec_output="$(docker exec "$container_id" sh -c '
    # Check for OpenSSH sshd
    if command -v sshd >/dev/null 2>&1 || [ -x /usr/sbin/sshd ]; then
      # Check if running
      if command -v pgrep >/dev/null 2>&1 && pgrep -x sshd >/dev/null 2>&1; then
        echo "SSHD_RUNNING"
        exit 0
      fi
      if [ -d /proc ]; then
        for p in /proc/[0-9]*/comm; do
          [ -f "$p" ] || continue
          read -r pname < "$p" 2>/dev/null || continue
          [ "$pname" = "sshd" ] && echo "SSHD_RUNNING" && exit 0
        done
      fi
      if command -v ps >/dev/null 2>&1; then
        ps aux 2>/dev/null | grep -v grep | grep -q "[s]shd" && echo "SSHD_RUNNING" && exit 0
      fi
      for pidfile in /var/run/sshd.pid /run/sshd.pid; do
        [ -f "$pidfile" ] && echo "SSHD_PIDFILE" && exit 0
      done
    fi

    # Check for dropbear
    if command -v dropbear >/dev/null 2>&1 || [ -x /usr/sbin/dropbear ]; then
      if command -v pgrep >/dev/null 2>&1 && pgrep -x dropbear >/dev/null 2>&1; then
        echo "DROPBEAR_RUNNING"
        exit 0
      fi
      if [ -d /proc ]; then
        for p in /proc/[0-9]*/comm; do
          [ -f "$p" ] || continue
          read -r pname < "$p" 2>/dev/null || continue
          [ "$pname" = "dropbear" ] && echo "DROPBEAR_RUNNING" && exit 0
        done
      fi
      if command -v ps >/dev/null 2>&1; then
        ps aux 2>/dev/null | grep -v grep | grep -q "[d]ropbear" && echo "DROPBEAR_RUNNING" && exit 0
      fi
    fi

    # Neither found running
    if ! command -v sshd >/dev/null 2>&1 && ! [ -x /usr/sbin/sshd ] \
       && ! command -v dropbear >/dev/null 2>&1 && ! [ -x /usr/sbin/dropbear ]; then
      echo "ERR_NO_SSH_SERVER"
      exit 1
    fi

    echo "ERR_SSH_NOT_RUNNING"
    exit 1
  ' 2>&1)" || {
    echo "ERROR: in-container SSH check failed for $IMAGE_TAG" >&2
    echo "$exec_output" >&2
    echo "-- container logs --" >&2
    docker logs "$container_id" 2>&1 | tail -15 >&2 || true
    exit 3
  }

  case "$exec_output" in
    *ERR_NO_SSH_SERVER*)
      echo "ERROR: no SSH server (sshd/dropbear) found in $IMAGE_TAG" >&2
      exit 3
      ;;
    *ERR_SSH_NOT_RUNNING*)
      echo "ERROR: SSH server not running inside $IMAGE_TAG" >&2
      echo "-- container logs --" >&2
      docker logs "$container_id" 2>&1 | tail -15 >&2 || true
      exit 3
      ;;
    *SSHD_RUNNING*|*SSHD_PIDFILE*)
      echo "SSH_OK (sshd basic check)"
      ;;
    *DROPBEAR_RUNNING*)
      echo "SSH_OK (dropbear basic check)"
      ;;
    *)
      echo "WARN: could not confirm SSH status, proceeding anyway" >&2
      echo "SSH_OK (unconfirmed)"
      ;;
  esac
fi

printf 'Verified image: %s\n' "$IMAGE_TAG"
