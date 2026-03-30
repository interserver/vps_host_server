#!/usr/bin/env bash

set -euo pipefail

############################
# CONFIG
############################
PARALLELISM=${PARALLELISM:-6}
ROOT_PASSWORD="InterServer!23"
WORKDIR="./dockerfarm"
LOGDIR="$WORKDIR/logs"
BUILDDIR="$WORKDIR/builds"
RESULTDIR="$WORKDIR/results"
TOKEN_CACHE="/tmp/dockerfarm_tokens"
MAX_RETRIES=5

mkdir -p "$LOGDIR" "$BUILDDIR" "$RESULTDIR" "$TOKEN_CACHE"

RESULT_JSON="$RESULTDIR/results.json"
ERROR_JSON="$RESULTDIR/errors.json"

echo "[]" > "$RESULT_JSON"
echo "{}" > "$ERROR_JSON"

DISTROS=(
  ubuntu debian fedora alpine archlinux
  amazonlinux oraclelinux rockylinux almalinux
)

############################
# DOCKER AUTH
############################
get_token() {
  local repo=$1
  local f="$TOKEN_CACHE/${repo}.token"

  if [[ -f "$f" ]]; then
    age=$(( $(date +%s) - $(stat -c %Y "$f") ))
    if (( age < 300 )); then cat "$f"; return; fi
  fi

  token=$(curl -s "https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/${repo}:pull" | jq -r .token)
  echo "$token" > "$f"
  echo "$token"
}

############################
# TAG DISCOVERY
############################
fetch_tags() {
  local image=$1
  local limit=10
  local url="https://hub.docker.com/v2/repositories/library/${image}/tags?page_size=100"
  local tags=()

  while [[ -n "$url" && ${#tags[@]} -lt $limit ]]; do
    resp=$(curl -s "$url")

    new=$(echo "$resp" | jq -r '
      .results[]
      | select(.images != null)
      | select(any(.images[]; .architecture=="amd64"))
      | .name
    ')

    while read -r t; do
      tags+=("$t")
      [[ ${#tags[@]} -ge $limit ]] && break
    done <<< "$new"

    url=$(echo "$resp" | jq -r .next)
    [[ "$url" == "null" ]] && url=""
  done

  echo "${tags[@]}"
}

############################
# REPO FIXES
############################
repo_fixes() {
cat <<'EOF'
RUN sed -i 's|deb.debian.org|archive.debian.org|g' /etc/apt/sources.list 2>/dev/null || true
RUN sed -i 's|security.debian.org|archive.debian.org|g' /etc/apt/sources.list 2>/dev/null || true
RUN echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid 2>/dev/null || true
RUN sed -i 's|mirror.centos.org|vault.centos.org|g' /etc/yum.repos.d/*.repo 2>/dev/null || true
RUN sed -i 's|^#.*baseurl=http|baseurl=http|g' /etc/yum.repos.d/*.repo 2>/dev/null || true
RUN dnf clean all 2>/dev/null || true
RUN sed -i 's|dl-cdn.alpinelinux.org|dl-3.alpinelinux.org|g' /etc/apk/repositories 2>/dev/null || true
RUN pacman-key --init 2>/dev/null || true
RUN pacman-key --populate archlinux 2>/dev/null || true
EOF
}

############################
# PLUGIN INSTALL
############################
install_cmd() {
  case "$1" in
    ubuntu|debian)
      echo "RUN apt-get update && apt-get install -y openssh-server sudo"
      ;;
    fedora|rockylinux|almalinux|oraclelinux)
      echo "RUN (dnf install -y openssh-server sudo || yum install -y openssh-server sudo)"
      ;;
    alpine)
      echo "RUN apk add --no-cache openssh sudo"
      ;;
    archlinux)
      echo "RUN pacman -Sy --noconfirm archlinux-keyring && pacman -Sy --noconfirm openssh sudo"
      ;;
    *)
      echo "RUN echo unsupported"
      ;;
  esac
}

############################
# DOCKERFILE GENERATION
############################
gen_dockerfile() {
  local distro=$1
  local tag=$2
  local dir="$BUILDDIR/${distro}_${tag}"

  mkdir -p "$dir"

  cat > "$dir/Dockerfile" <<EOF
FROM ${distro}:${tag}

$(repo_fixes)

$(install_cmd $distro)

RUN mkdir -p /var/run/sshd
RUN echo "root:${ROOT_PASSWORD}" | chpasswd || true
RUN sed -i 's/#PermitRootLogin.*/PermitRootLogin yes/' /etc/ssh/sshd_config || true
RUN sed -i 's/#PasswordAuthentication.*/PasswordAuthentication yes/' /etc/ssh/sshd_config || true

EXPOSE 22
CMD ["/usr/sbin/sshd","-D"]
EOF
}

############################
# JSON RECORDING
############################
record_result() {
  tmp=$(mktemp)
  jq ". += [$1]" "$RESULT_JSON" > "$tmp" && mv "$tmp" "$RESULT_JSON"
}

record_error() {
  tmp=$(mktemp)
  jq --arg e "$1" --arg n "$2" '
    .[$e] += [$n] // .[$e] = [$n]
  ' "$ERROR_JSON" > "$tmp" && mv "$tmp" "$ERROR_JSON"
}

############################
# BUILD + TEST
############################
build_one() {
  local name=$1
  local dir="$BUILDDIR/$name"
  local log="$LOGDIR/$name.log"

  for ((i=0;i<MAX_RETRIES;i++)); do
    docker build "$dir" -t "df:$name" 2>&1 | tee "$log" && break

    if grep -Ei "429|toomanyrequests" "$log"; then
      sleep 30
    fi
  done

  cid=$(docker run -d -p 0:22 "df:$name")
  port=$(docker port "$cid" 22 | cut -d: -f2)

  sleep 5

  if sshpass -p "$ROOT_PASSWORD" ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 root@127.0.0.1 -p "$port" "echo ok" &>/dev/null; then
    record_result "{\"image\":\"$name\",\"status\":\"success\",\"ssh\":true}"
  else
    record_result "{\"image\":\"$name\",\"status\":\"failed\",\"ssh\":false}"
    record_error "ssh_failed" "$name"
  fi

  docker rm -f "$cid" >/dev/null
}

export -f build_one record_result record_error

############################
# TUI DASHBOARD
############################
tui() {
  while true; do
    clear
    echo "==== DockerFarm Dashboard ===="

    total=$(jq length "$RESULT_JSON")
    ok=$(jq '[.[]|select(.status=="success")]|length' "$RESULT_JSON")
    fail=$(jq '[.[]|select(.status=="failed")]|length' "$RESULT_JSON")

    echo "Total: $total | OK: $ok | FAIL: $fail"
    echo ""

    jq -r '.[-10:][]|"\(.image): \(.status)"' "$RESULT_JSON"

    echo ""
    echo "Errors:"
    jq -r 'to_entries[]|"\(.key): \(.value|length)"' "$ERROR_JSON" 2>/dev/null || true

    sleep 2
  done
}

############################
# COMMANDS
############################
init() {
  for d in "${DISTROS[@]}"; do
    tags=$(fetch_tags "$d")
    for t in $tags; do
      gen_dockerfile "$d" "$t"
    done
  done
}

run() {
  tui &
  find "$BUILDDIR" -mindepth 1 -maxdepth 1 -type d -printf "%f\n" \
    | xargs -P $PARALLELISM -I{} bash -c 'build_one "$@"' _ {}
}

case "${1:-}" in
  init) init ;;
  run) run ;;
  *) echo "Usage: $0 {init|run}" ;;
esac
