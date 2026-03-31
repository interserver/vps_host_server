#!/usr/bin/env bash
# lib/verify.sh — SSH verification for built images

gb_verify_image() {
  local image_tag="$1" root_password="$2"
  local container_id=""

  _verify_cleanup() {
    [[ -n "$container_id" ]] && docker rm -f "$container_id" >/dev/null 2>&1 || true
  }
  trap _verify_cleanup RETURN

  # Start container
  container_id="$(docker run -d -P "$image_tag" tail -f /dev/null 2>&1)" || {
    echo "ERROR: container failed to start for $image_tag" >&2
    return 2
  }

  sleep 2

  # Verify running
  local state
  state="$(docker inspect -f '{{.State.Status}}' "$container_id" 2>/dev/null || echo "unknown")"
  if [[ "$state" != "running" ]]; then
    echo "ERROR: container not running (state=$state) for $image_tag" >&2
    docker logs "$container_id" 2>&1 | tail -10 >&2 || true
    return 2
  fi

  # Get published port
  local port=""
  for _ in $(seq 1 10); do
    port="$(docker port "$container_id" 22/tcp 2>/dev/null | awk -F: 'NR==1 {print $NF}')" || true
    [[ -n "$port" ]] && break
    sleep 1
  done

  if [[ -z "$port" ]]; then
    echo "ERROR: no published port for 22/tcp in $image_tag" >&2
    docker logs "$container_id" 2>&1 | tail -10 >&2 || true
    return 2
  fi

  # Wait for SSH ready
  for _ in $(seq 1 30); do
    if command -v nc >/dev/null 2>&1 && nc -z 127.0.0.1 "$port" 2>/dev/null; then
      break
    elif (echo >/dev/tcp/127.0.0.1/"$port") 2>/dev/null; then
      break
    fi
    sleep 1
  done

  # Test SSH login
  if command -v sshpass >/dev/null 2>&1 && command -v ssh >/dev/null 2>&1; then
    if sshpass -p "$root_password" ssh \
        -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
        -o ConnectTimeout=15 -o ServerAliveInterval=5 \
        -p "$port" root@127.0.0.1 \
        'id -u | grep -q "^0$" && echo SSH_OK' 2>/dev/null; then
      echo "Verified: $image_tag (sshpass)"
      return 0
    fi
    # Retry with legacy algorithms (dropbear compat)
    if sshpass -p "$root_password" ssh \
        -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null \
        -o ConnectTimeout=15 \
        -o PubkeyAcceptedAlgorithms=+ssh-rsa -o HostKeyAlgorithms=+ssh-rsa \
        -p "$port" root@127.0.0.1 \
        'echo SSH_OK' 2>/dev/null; then
      echo "Verified: $image_tag (sshpass+legacy)"
      return 0
    fi
    echo "ERROR: SSH login failed for $image_tag on port $port" >&2
    docker logs "$container_id" 2>&1 | tail -10 >&2 || true
    return 3
  fi

  # Fallback: in-container check
  local exec_out
  exec_out="$(docker exec "$container_id" sh -c '
    # Check sshd
    for proc in sshd dropbear; do
      if command -v pgrep >/dev/null 2>&1 && pgrep -x "$proc" >/dev/null 2>&1; then
        echo "${proc}_RUNNING"; exit 0
      fi
      if [ -d /proc ]; then
        for p in /proc/[0-9]*/comm; do
          [ -f "$p" ] || continue
          read -r pn < "$p" 2>/dev/null || continue
          [ "$pn" = "$proc" ] && echo "${proc}_RUNNING" && exit 0
        done
      fi
    done
    # Check if binary exists at all
    command -v sshd >/dev/null 2>&1 && echo "SSHD_EXISTS" && exit 0
    [ -x /usr/sbin/sshd ] && echo "SSHD_EXISTS" && exit 0
    command -v dropbear >/dev/null 2>&1 && echo "DROPBEAR_EXISTS" && exit 0
    echo "NO_SSH_SERVER"; exit 1
  ' 2>&1)" || true

  case "$exec_out" in
    *_RUNNING*) echo "Verified: $image_tag (in-container)"; return 0 ;;
    *_EXISTS*)  echo "WARN: SSH binary exists but not running in $image_tag" >&2; return 3 ;;
    *)          echo "ERROR: no SSH server in $image_tag" >&2; return 3 ;;
  esac
}
