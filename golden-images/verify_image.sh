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

container_id="$(docker run -d -P "$IMAGE_TAG" sleep infinity)"

port="$(docker port "$container_id" 22/tcp | awk -F: 'NR==1 {print $NF}')"
if [[ -z "$port" ]]; then
  echo "ERROR: no published port for 22/tcp in $IMAGE_TAG" >&2
  exit 2
fi

for _ in $(seq 1 20); do
  if command -v nc >/dev/null 2>&1 && nc -z 127.0.0.1 "$port" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

if command -v sshpass >/dev/null 2>&1 && command -v ssh >/dev/null 2>&1; then
  sshpass -p "$ROOT_PASSWORD" ssh \
    -o StrictHostKeyChecking=no \
    -o UserKnownHostsFile=/dev/null \
    -o ConnectTimeout=5 \
    -p "$port" root@127.0.0.1 \
    'id -u | grep -q "^0$" && echo SSH_OK'
else
  echo "WARN: sshpass/ssh not found; using in-container sshd sanity check only." >&2
  docker exec "$container_id" sh -lc 'command -v sshd >/dev/null && pgrep -x sshd >/dev/null'
  echo "SSH_OK (basic)"
fi

printf 'Verified image: %s\n' "$IMAGE_TAG"
