#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RENDER_SCRIPT="$ROOT_DIR/render_dockerfile.sh"
VERIFY_SCRIPT="$ROOT_DIR/verify_image.sh"

MATRIX_FILE="${1:-$ROOT_DIR/images.matrix}"
ROOT_PASSWORD="${ROOT_PASSWORD:-interser123}"
REGISTRY_PREFIX="${REGISTRY_PREFIX:-interserver}"
PUSH_IMAGES="${PUSH_IMAGES:-0}"
VERIFY_IMAGES="${VERIFY_IMAGES:-1}"
PARALLELISM="${PARALLELISM:-8}"
WORK_DIR="${WORK_DIR:-$ROOT_DIR/build}"
DNS_SERVERS="${DNS_SERVERS:-8.8.8.8,8.8.4.4}"

if [[ ! -f "$MATRIX_FILE" ]]; then
  echo "ERROR: matrix file not found: $MATRIX_FILE" >&2
  exit 1
fi

mkdir -p "$WORK_DIR"

unsupported_family() {
  local family="$1"
  case "$family" in
    cirros|sl|busybox)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

normalize_line_to_pairs() {
  # Supported input formats:
  # 1) base_image
  # 2) base_image,golden_tag
  # 3) family: version1,version2,version3,
  local line="$1"
  line="${line%%#*}"
  line="$(echo "$line" | tr -d '\r' | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
  [[ -z "$line" ]] && return 0

  if [[ "$line" == *":"* && "$line" == *,* && "$line" != *,*:* ]]; then
    local family versions version base tag
    family="${line%%:*}"
    versions="${line#*:}"
    family="$(echo "$family" | xargs)"

    IFS=',' read -r -a v_arr <<< "$versions"
    for version in "${v_arr[@]}"; do
      version="$(echo "$version" | xargs)"
      [[ -z "$version" ]] && continue
      if unsupported_family "$family"; then
        echo "SKIP,$family:$version,unsupported_family"
        continue
      fi
      base="$family:$version"
      tag="$REGISTRY_PREFIX/${family}-${version}-ssh"
      echo "BUILD,$base,$tag"
    done
    return 0
  fi

  IFS=',' read -r col1 col2 _extra <<< "$line"
  col1="$(echo "${col1:-}" | xargs)"
  col2="$(echo "${col2:-}" | xargs)"

  [[ -z "$col1" ]] && return 0

  if unsupported_family "${col1%%:*}"; then
    echo "SKIP,$col1,unsupported_family"
    return 0
  fi

  if [[ -n "$col2" ]]; then
    echo "BUILD,$col1,$col2"
  else
    echo "BUILD,$col1,$REGISTRY_PREFIX/${col1/:/-}-ssh"
  fi
}

build_one() {
  local base="$1"
  local tag="$2"
  local safe_name
  safe_name="$(echo "$tag" | sed 's#[/:]#_#g')"
  local out_dir="$WORK_DIR/$safe_name"

  "$RENDER_SCRIPT" "$base" "$tag" "$ROOT_PASSWORD" "$out_dir"

  local dns_args=()
  IFS=',' read -r -a dns_list <<< "$DNS_SERVERS"
  for dns in "${dns_list[@]}"; do
    dns_args+=(--dns "$dns")
  done

  echo "==> Building $tag from $base"
  docker build --pull "${dns_args[@]}" --build-arg ROOT_PASSWORD="$ROOT_PASSWORD" -t "$tag" "$out_dir"

  if [[ "$VERIFY_IMAGES" == "1" ]]; then
    "$VERIFY_SCRIPT" "$tag" "$ROOT_PASSWORD"
  fi

  if [[ "$PUSH_IMAGES" == "1" ]]; then
    echo "==> Pushing $tag"
    docker push "$tag"
  fi

  echo "DONE,$base,$tag"
}

export ROOT_DIR RENDER_SCRIPT VERIFY_SCRIPT ROOT_PASSWORD REGISTRY_PREFIX PUSH_IMAGES VERIFY_IMAGES WORK_DIR DNS_SERVERS
export -f build_one unsupported_family normalize_line_to_pairs

plan_file="$WORK_DIR/build-plan.csv"
: > "$plan_file"

while IFS= read -r line || [[ -n "$line" ]]; do
  while IFS= read -r normalized; do
    [[ -z "$normalized" ]] && continue
    echo "$normalized" >> "$plan_file"
  done < <(normalize_line_to_pairs "$line")
done < "$MATRIX_FILE"

build_queue="$WORK_DIR/build-queue.csv"
skip_log="$WORK_DIR/skip-log.csv"
awk -F',' '$1=="BUILD"{print $2","$3}' "$plan_file" > "$build_queue"
awk -F',' '$1=="SKIP"{print $2","$3}' "$plan_file" > "$skip_log" || true

if [[ -s "$skip_log" ]]; then
  echo "Some templates were skipped:" >&2
  cat "$skip_log" >&2
fi

if [[ ! -s "$build_queue" ]]; then
  echo "Nothing to build." >&2
  exit 0
fi

# shellcheck disable=SC2016
cat "$build_queue" | xargs -P "$PARALLELISM" -n 1 -I {} bash -lc '
  pair="{}"
  base="${pair%%,*}"
  tag="${pair#*,}"
  build_one "$base" "$tag"
'

echo "All done. Build queue: $build_queue"
echo "Skip log: $skip_log"
