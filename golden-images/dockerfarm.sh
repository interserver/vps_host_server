#!/usr/bin/env bash

set -euo pipefail

############################
# CONFIG
############################
PARALLELISM=${PARALLELISM:-6}
ROOT_PASSWORD="InterServer!23"

WORKDIR="./dockerfarm"
BUILDDIR="$WORKDIR/builds"
LOGDIR="$WORKDIR/logs"
RESULTDIR="$WORKDIR/results"
TOKEN_CACHE="/tmp/dockerfarm_tokens"

MAX_RETRIES=5

mkdir -p "$BUILDDIR" "$LOGDIR" "$RESULTDIR" "$TOKEN_CACHE"

RESULT_JSON="$RESULTDIR/results.json"
ERROR_JSON="$RESULTDIR/errors.json"

[[ -f "$RESULT_JSON" ]] || echo "[]" > "$RESULT_JSON"
[[ -f "$ERROR_JSON" ]] || echo "{}" > "$ERROR_JSON"

DISTROS=(
  ubuntu debian fedora alpine archlinux
  amazonlinux oraclelinux rockylinux almalinux
)

############################
# DEP CHECK
############################
command -v jq >/dev/null || { echo "jq required"; exit 1; }
command -v docker >/dev/null || { echo "docker required"; exit 1; }
command -v sshpass >/dev/null || {
  echo "[*] Installing sshpass..."
  sudo apt-get install -y sshpass 2>/dev/null || sudo yum install -y sshpass || true
}

############################
# UTIL
############################
sanitize() {
  echo "$1" | tr '/:@' '___'
}

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
# PRE-VALIDATE TAG (KEY ADDITION)
############################
validate_tag() {
  local repo=$1
  local tag=$2

  token=$(get_token "$repo")

  code=$(curl -s -o /dev/null -w "%{http_code}" \
    -H "Authorization: Bearer $token" \
    -H "Accept: application/vnd.docker.distribution.manifest.v2+json" \
    "https://registry-1.docker.io/v2/library/${repo}/manifests/${tag}")

  # Only accept v2 manifests (200 OK)
  if [[ "$code" == "200" ]]; then
    return 0
  fi

  return 1
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

    for t in $new; do
      echo "[*] Validating $image:$t"

      if validate_tag "$image" "$t"; then
        tags+=("$t")
      else
        echo "[!] Skipping invalid tag $image:$t"
      fi

      [[ ${#tags[@]} -ge $limit ]] && break
    done

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
# INSTALL LOGIC
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

  [[ "$distro" =~ (busybox|cirros) ]] && return

  local safe_tag
  safe_tag=$(sanitize "$tag")
  local name="${distro}_${safe_tag}"
  local dir="$BUILDDIR/$name"

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
# JSON (LOCKED)
############################
record_result() {
  local data=$1
  local tmp=$(mktemp)

  (
    flock 200
    jq ". += [$data]" "$RESULT_JSON" > "$tmp" && mv "$tmp" "$RESULT_JSON"
  ) 200>"$RESULT_JSON.lock"
}

record_error() {
  local err=$1
  local name=$2
  local tmp=$(mktemp)

  (
    flock 200
    jq --arg e "$err" --arg n "$name" \
      '.[$e] += [$n] // .[$e] = [$n]' \
      "$ERROR_JSON" > "$tmp" && mv "$tmp" "$ERROR_JSON"
  ) 200>"$ERROR_JSON.lock"
}

############################
# BUILD + TEST
############################
test_container() {
  local name=$1

  cid=$(docker run -d -p 0:22 "df:$name" 2>/dev/null || true)

  [[ -z "$cid" ]] && {
    record_result "{\"image\":\"$name\",\"status\":\"run_failed\",\"ssh\":false}"
    record_error "run_failed" "$name"
    return
  }

  port=$(docker port "$cid" 22 | cut -d: -f2)
  sleep 5

  if sshpass -p "$ROOT_PASSWORD" ssh -o StrictHostKeyChecking=no -o ConnectTimeout=5 root@127.0.0.1 -p "$port" "echo ok" &>/dev/null; then
    record_result "{\"image\":\"$name\",\"status\":\"success\",\"ssh\":true}"
  else
    record_result "{\"image\":\"$name\",\"status\":\"ssh_failed\",\"ssh\":false}"
    record_error "ssh_failed" "$name"
  fi

  docker rm -f "$cid" >/dev/null 2>&1
}

build_one() {
  local name=$1
  local dir="$BUILDDIR/$name"
  local log="$LOGDIR/$name.log"

  local built=0

  for ((i=0;i<MAX_RETRIES;i++)); do
    docker build "$dir" -t "df:$name" 2>&1 | tee "$log"
    rc=${PIPESTATUS[0]}

    [[ $rc -eq 0 ]] && { built=1; break; }

    grep -Ei "429|toomanyrequests" "$log" && sleep 30 || sleep 5
  done

  [[ $built -ne 1 ]] && {
    record_result "{\"image\":\"$name\",\"status\":\"build_failed\",\"ssh\":false}"
    record_error "build_failed" "$name"
    return
  }

  test_container "$name"
}

export -f build_one test_container record_result record_error

############################
# TUI
############################
tui() {
  while true; do
    clear
    echo "==== DockerFarm Dashboard ===="

    total=$(jq length "$RESULT_JSON" 2>/dev/null || echo 0)
    ok=$(jq '[.[]|select(.status=="success")]|length' "$RESULT_JSON" 2>/dev/null || echo 0)
    fail=$(jq '[.[]|select(.status!="success")]|length' "$RESULT_JSON" 2>/dev/null || echo 0)

    echo "Total: $total | OK: $ok | FAIL: $fail"
    echo ""

    jq -r '.[-10:][]|"\(.image): \(.status)"' "$RESULT_JSON" 2>/dev/null || true

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
    echo "[*] Processing $d"
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
