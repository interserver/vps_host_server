#!/bin/bash
#
# Docker SSH Image Builder v2
# Advanced features: TUI Dashboard, Registry Auth, Per-distro Plugins, JSON Output
#

set -o pipefail
set -o errtrace
set -o functrace

# Version
VERSION="2.0.0"

# Configuration
PARALLELISM="${PARALLELISM:-6}"
SSH_PASSWORD="${SSH_PASSWORD:-InterServer!23}"
LOG_DIR="/workspace/docker-ssh-builder/logs"
DOCKERFILE_DIR="/workspace/docker-ssh-builder/dockerfiles"
OUTPUT_DIR="/workspace/docker-ssh-builder/output"
STATE_FILE="$OUTPUT_DIR/state.json"
RESULTS_FILE="$OUTPUT_DIR/results.json"
SPACE_THRESHOLD_MB=5000
MAX_RETRIES=3
RETRY_DELAY=10
RATE_LIMIT_BACKOFF=120
TAG_PAGE_SIZE=20
MAX_TAGS_PER_OS=10

# Trap for cleanup
trap 'cleanup' EXIT INT TERM

# Colors for TUI
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
WHITE='\033[1;37m'
NC='\033[0m'
BOLD='\033[1m'
DIM='\033[2m'

# Box drawing characters
BOX_TOP_LEFT='┌'
BOX_TOP_RIGHT='┐'
BOX_BOTTOM_LEFT='└'
BOX_BOTTOM_RIGHT='┘'
BOX_HORIZONTAL='─'
BOX_VERTICAL='│'
BOX_CROSS='┼'

# Global state
declare -A GOOD_TEMPLATES
declare -A BAD_TEMPLATES
declare -A BUILD_STATUS      # os:version -> pending/building/success/failed
declare -A BUILD_ERRORS       # os:version -> error message
declare -A ERROR_GROUPS
declare -A ACTIVE_BUILDS
declare -A DOCKER_TOKEN      # Cache for registry tokens
declare -A OS_PLUGIN_HANDLERS
BUILD_QUEUE=()
TOTAL_SUCCESS=0
TOTAL_FAILED=0
TOTAL_PENDING=0
START_TIME=$(date +%s)
CURRENT_BUILD=""
LAST_UPDATE=$(date +%s)
TERMINAL_LINES=0
TERMINAL_COLS=0

# OS Configuration with plugin handlers
declare -A OS_CONFIG=(
    ["alpine"]="apk|AlpineHandler"
    ["ubuntu"]="apt|UbuntuHandler"
    ["debian"]="apt|DebianHandler"
    ["fedora"]="dnf|FedoraHandler"
    ["amazonlinux"]="yum|AmazonHandler"
    ["oraclelinux"]="dnf|OracleHandler"
    ["photon"]="tdnf|PhotonHandler"
    ["busybox"]="none|BusyBoxHandler"
    ["cirros"]="none|CirrosHandler"
    ["mageia"]="dnf|MageiaHandler"
    ["archlinux"]="pacman|ArchHandler"
    ["sl"]="zypper|SLESHandler"
    ["opensuse"]="zypper|SLESHandler"
    ["almalinux"]="dnf|AlmaHandler"
    ["rockylinux"]="dnf|RockyHandler"
)

ALL_OS=(
    "busybox" "ubuntu" "fedora" "debian" "cirros" "mageia"
    "oraclelinux" "alpine" "photon" "amazonlinux" "almalinux"
    "rockylinux" "sl" "archlinux"
)

# ============================================================
# TUI Functions
# ============================================================

init_tui() {
    TERMINAL_LINES=$(tput lines 2>/dev/null || echo 40)
    TERMINAL_COLS=$(tput cols 2>/dev/null || echo 120)
    clear
    hide_cursor
}

restore_tui() {
    show_cursor
    clear
}

hide_cursor() {
    printf '\033[?25l'
}

show_cursor() {
    printf '\033[?25h'
}

move_cursor() {
    printf '\033[%d;%dH' "$1" "$2"
}

clear_line() {
    printf '\033[2K'
}

clear_screen() {
    printf '\033[2J'
}

draw_box() {
    local x1=$1 y1=$2 x2=$3 y2=$4
    local width=$((x2 - x1))
    local height=$((y2 - y1))

    # Top line
    move_cursor $y1 $x1
    printf '%s' "$BOX_TOP_LEFT"
    printf '%s' "$(printf '%*s' $width '' | tr ' ' "$BOX_HORIZONTAL")"
    printf '%s\n' "$BOX_TOP_RIGHT"

    # Middle lines
    for ((i=1; i<height; i++)); do
        move_cursor $((y1 + i)) $x1
        printf '%s%*s%s\n' "$BOX_VERTICAL" $width '' "$BOX_VERTICAL"
    done

    # Bottom line
    move_cursor $y2 $x1
    printf '%s' "$BOX_BOTTOM_LEFT"
    printf '%s' "$(printf '%*s' $width '' | tr ' ' "$BOX_HORIZONTAL")"
    printf '%s\n' "$BOX_BOTTOM_RIGHT"
}

draw_header() {
    local title="Docker SSH Image Builder v${VERSION}"
    local runtime=$(format_duration $(($(date +%s) - START_TIME)))

    move_cursor 1 1
    printf '%s' "$CYAN$BOLD"
    printf '%s' "$BOX_TOP_LEFT"
    printf '%s' "$(printf '%*s' $((TERMINAL_COLS - 2)) '' | tr ' ' "$BOX_HORIZONTAL")"
    printf '%s\n' "$BOX_TOP_RIGHT"

    move_cursor 2 1
    printf '%s' "$BOX_VERTICAL"
    printf '%s' "$(printf '%-40s %-40s %-*s' "PARALLELISM: $PARALLELISM" "SSH PASSWORD: ********" $((TERMINAL_COLS - 95)) '')"
    printf '%s\n' "$BOX_VERTICAL"

    move_cursor 3 1
    printf '%s' "$BOX_VERTICAL"
    printf '%s' "$(printf '%-40s %-40s %-*s' "STARTED: $(date -d @$START_TIME '+%H:%M:%S')" "RUNTIME: $runtime" $((TERMINAL_COLS - 95)) '')"
    printf '%s\n' "$BOX_VERTICAL"

    move_cursor 4 1
    printf '%s' "$BOX_BOTTOM_LEFT"
    printf '%s' "$(printf '%*s' $((TERMINAL_COLS - 2)) '' | tr ' ' "$BOX_HORIZONTAL")"
    printf '%s\n' "$BOX_BOTTOM_RIGHT"
    printf '%s' "$NC"
}

draw_summary_panel() {
    local x=2 y=6 width=$((TERMINAL_COLS - 4)) height=10

    move_cursor $y $x
    printf '%s' "$YELLOW$BOLD"
    printf '%s' "$BOX_TOP_LEFT"
    printf '%s' "$(printf '%*s' $((width - 2)) '' | tr ' ' "$BOX_HORIZONTAL")"
    printf '%s' "$BOX_TOP_RIGHT"
    printf '%s\n' "$NC"

    move_cursor $((y + 1)) $x
    printf '%s' "$BOX_VERTICAL"
    printf ' %s %-60s %s\n' "$YELLOW" "OVERALL PROGRESS" "$NC" "$BOX_VERTICAL"

    move_cursor $((y + 2)) $x
    printf '%s' "$BOX_VERTICAL"
    printf '%s\n' "$NC"

    move_cursor $((y + 3)) $x
    printf '%s' "$BOX_VERTICAL"
    printf '   '
    local total=$((TOTAL_SUCCESS + TOTAL_FAILED + TOTAL_PENDING))
    local success_pct=$((total > 0 ? TOTAL_SUCCESS * 100 / total : 0))
    local fail_pct=$((total > 0 ? TOTAL_FAILED * 100 / total : 0))
    local pending_pct=$((total > 0 ? TOTAL_PENDING * 100 / total : 0))

    # Progress bar
    printf '%s[%s' "$GREEN" "$(printf '%*s' $success_pct '' | tr ' ' '█')"
    printf '%s%s' "$RED" "$(printf '%*s' $fail_pct '' | tr ' ' '█')"
    printf '%s%s' "$DIM" "$(printf '%*s' $pending_pct '' | tr ' ' '░')"
    printf '%s]%s\n' "$NC" "$BOX_VERTICAL"

    move_cursor $((y + 4)) $x
    printf '%s' "$BOX_VERTICAL"
    printf '   '
    printf '%s✓ SUCCESS: %-5d (%3d%%)%s' "$GREEN" "$TOTAL_SUCCESS" "$success_pct" "$NC"
    printf '  '
    printf '%s✗ FAILED:  %-5d (%3d%%)%s' "$RED" "$TOTAL_FAILED" "$fail_pct" "$NC"
    printf '  '
    printf '%s○ PENDING: %-5d%s' "$DIM" "$TOTAL_PENDING" "$NC"
    printf '%*s%s\n' $((width - 85)) '' "$BOX_VERTICAL"

    move_cursor $((y + 5)) $x
    printf '%s' "$BOX_VERTICAL"
    printf '   '
    printf '%s█ Build Progress: %d/%d complete%s' "$CYAN" $((TOTAL_SUCCESS + TOTAL_FAILED)) "$total" "$NC"
    printf '%*s%s\n' $((width - 50)) '' "$BOX_VERTICAL"

    move_cursor $((y + 6)) $x
    printf '%s' "$BOX_VERTICAL"
    printf '   '
    local rate=$((TOTAL_SUCCESS + TOTAL_FAILED > 0 ? TOTAL_SUCCESS * 100 / (TOTAL_SUCCESS + TOTAL_FAILED) : 0))
    printf '%sSuccess Rate: %d%%%s' "$MAGENTA" "$rate" "$NC"
    printf '%*s%s\n' $((width - 40)) '' "$BOX_VERTICAL"

    move_cursor $((y + 7)) $x
    printf '%s' "$BOX_VERTICAL"
    printf '%s\n' "$NC"

    move_cursor $((y + 8)) $x
    printf '%s' "$BOX_BOTTOM_LEFT"
    printf '%s' "$(printf '%*s' $((width - 2)) '' | tr ' ' "$BOX_HORIZONTAL")"
    printf '%s\n' "$BOX_BOTTOM_RIGHT"
    printf '%s' "$NC"
}

draw_build_grid() {
    local y=$((TERMINAL_LINES - 20))
    local x=2
    local col_width=30
    local cols=$(( (TERMINAL_COLS - 4) / col_width ))
    local row=0
    local col=0

    move_cursor $y $x
    printf '%s%s BUILD STATUS GRID %s\n' "$CYAN$BOLD" "$BOX_TOP_LEFT" "$BOX_TOP_RIGHT$NC"

    local count=0
    for os in "${ALL_OS[@]}"; do
        for version in $(get_cached_tags "$os" 2>/dev/null | head -1); do
            local status="${BUILD_STATUS[$os:$version]:-pending}"
            local display_name="${os}:${version:0:12}"
            local status_color="$DIM"
            local status_icon="○"

            case "$status" in
                success)
                    status_color="$GREEN"
                    status_icon="✓"
                    ;;
                failed)
                    status_color="$RED"
                    status_icon="✗"
                    ;;
                building)
                    status_color="$YELLOW"
                    status_icon="◐"
                    ;;
                pending)
                    status_color="$DIM"
                    status_icon="○"
                    ;;
            esac

            if [[ $col -eq 0 ]]; then
                move_cursor $((y + 1 + row)) $x
                printf '%s' "$BOX_VERTICAL "
            fi

            printf '%s%2s %-20s%s' "$status_color" "$status_icon" "$display_name" "$NC"
            printf '%*s' $((col_width - 25)) ''

            if [[ $col -eq $((cols - 1)) ]]; then
                printf '%s\n' "$BOX_VERTICAL"
                col=0
                row=$((row + 1))
            else
                printf '%s' "$BOX_VERTICAL "
                col=$((col + 1))
            fi

            count=$((count + 1))
            if [[ $count -ge 40 ]]; then
                break 2
            fi
        done
    done

    # Fill remaining cells
    while [[ $col -lt $cols ]] && [[ $count -lt 40 ]]; do
        if [[ $col -eq 0 ]]; then
            move_cursor $((y + 1 + row)) $x
            printf '%s' "$BOX_VERTICAL "
        fi
        printf '%*s' $((col_width - 1)) ''
        printf '%s' "$BOX_VERTICAL "
        col=$((col + 1))
        count=$((count + 1))
    done

    if [[ $col -gt 0 ]]; then
        printf '\n'
    fi
}

draw_error_summary() {
    local y=$((TERMINAL_LINES - 8))
    local x=2
    local width=$((TERMINAL_COLS - 4))
    local error_count=0

    move_cursor $y $x
    printf '%s%s ERROR SUMMARY %s\n' "$RED$BOLD" "$BOX_TOP_LEFT" "$BOX_TOP_RIGHT$NC"

    for key in "${!ERROR_GROUPS[@]}"; do
        local data="${ERROR_GROUPS[$key]}"
        local error_msg=$(echo "$data" | cut -d'|' -f1)
        local files=$(echo "$data" | cut -d'|' -f2- | tr ',' '\n' | wc -l)

        move_cursor $((y + 1 + error_count)) $x
        printf '%s' "$BOX_VERTICAL"
        printf ' %s%2d.%s %-50s (affects %d files)%*s%s\n' \
            "$RED" "$((error_count + 1))" "$NC" "${error_msg:0:50}" "$files" \
            $((width - 75)) '' "$BOX_VERTICAL"

        error_count=$((error_count + 1))
        if [[ $error_count -ge 5 ]]; then
            break
        fi
    done

    # Fill remaining
    while [[ $error_count -lt 5 ]]; do
        move_cursor $((y + 1 + error_count)) $x
        printf '%s%*s%s\n' "$BOX_VERTICAL" $((width - 1)) '' "$BOX_VERTICAL"
        error_count=$((error_count + 1))
    done

    move_cursor $((y + 6)) $x
    printf '%s' "$BOX_BOTTOM_LEFT"
    printf '%s' "$(printf '%*s' $((width - 2)) '' | tr ' ' "$BOX_HORIZONTAL")"
    printf '%s\n' "$BOX_BOTTOM_RIGHT"
    printf '%s' "$NC"
}

draw_footer() {
    local y=$((TERMINAL_LINES - 1))
    move_cursor $y 1
    printf '%s%s [%s] Logs: %s | Dockerfiles: %s | State: %s%s\n' \
        "$DIM" "$BOX_VERTICAL" "$(date '+%H:%M:%S')" \
        "$LOG_DIR" "$DOCKERFILE_DIR" "$OUTPUT_DIR" "$BOX_VERTICAL$NC"
}

draw_current_build() {
    local y=17
    local x=2
    local width=$((TERMINAL_COLS - 4))

    move_cursor $y $x
    printf '%s%s CURRENT BUILD %s\n' "$MAGENTA$BOLD" "$BOX_TOP_LEFT" "$BOX_TOP_RIGHT$NC"

    move_cursor $((y + 1)) $x
    printf '%s' "$BOX_VERTICAL"
    printf ' %s' "$WHITE"
    if [[ -n "$CURRENT_BUILD" ]]; then
        printf '%-60s' "Building: $CURRENT_BUILD"
    else
        printf '%-60s' "Waiting for next build..."
    fi
    printf '%s\n' "$NC$BOX_VERTICAL"

    move_cursor $((y + 2)) $x
    printf '%s' "$BOX_BOTTOM_LEFT"
    printf '%s' "$(printf '%*s' $((width - 2)) '' | tr ' ' "$BOX_HORIZONTAL")"
    printf '%s\n' "$BOX_BOTTOM_RIGHT"
    printf '%s' "$NC"
}

update_tui() {
    LAST_UPDATE=$(date +%s)
    draw_header
    draw_summary_panel
    draw_current_build
    draw_build_grid
    draw_error_summary
    draw_footer
    move_cursor $((TERMINAL_LINES)) 1
}

format_duration() {
    local seconds=$1
    local hours=$((seconds / 3600))
    local minutes=$(( (seconds % 3600) / 60 ))
    local secs=$((seconds % 60))
    printf '%02d:%02d:%02d' $hours $minutes $secs
}

# ============================================================
# JSON Output Functions
# ============================================================

init_json_output() {
    mkdir -p "$OUTPUT_DIR"
    cat > "$STATE_FILE" << 'JSON'
{
    "version": "2.0.0",
    "started_at": "",
    "ended_at": "",
    "configuration": {
        "parallelism": 6,
        "ssh_password_set": true,
        "max_retries": 3
    },
    "statistics": {
        "total_success": 0,
        "total_failed": 0,
        "total_pending": 0
    },
    "builds": [],
    "errors": []
}
JSON
    update_json_field ".started_at" "$(date -Iseconds)"
}

update_json_field() {
    local path="$1"
    local value="$2"
    local temp_file=$(mktemp)

    # Use jq if available, otherwise use sed
    if command -v jq &>/dev/null; then
        jq "$path = $value" "$STATE_FILE" > "$temp_file" && mv "$temp_file" "$STATE_FILE"
    else
        # Fallback: simple text replacement
        sed -i "s|\"$path\":.*|\"$path\": $value|" "$STATE_FILE" 2>/dev/null || true
    fi
}

append_build_to_json() {
    local os="$1"
    local version="$2"
    local status="$3"
    local error_msg="${4:-}"
    local image_tag="ssh-${os}:${version//\//-}"
    local duration=$(($(date +%s) - START_TIME))

    local build_entry=$(cat << JSON
{
    "os": "$os",
    "version": "$version",
    "image_tag": "$image_tag",
    "status": "$status",
    "error": "$error_msg",
    "dockerfile": "${DOCKERFILE_DIR}/${os}_${version//\//-}.dockerfile",
    "log_file": "${LOG_DIR}/${os}_${version//\//-}.log",
    "timestamp": "$(date -Iseconds)"
}
JSON
)

    # Append to builds array
    if command -v jq &>/dev/null; then
        local temp_file=$(mktemp)
        jq ".builds += [$build_entry]" "$STATE_FILE" > "$temp_file" && mv "$temp_file" "$STATE_FILE"
    fi
}

write_results_json() {
    local temp_results=$(mktemp)

    cat > "$temp_results" << 'JSONHEADER'
{
    "results": {
        "version": "2.0.0",
        "completed_at": "",
        "duration_seconds": 0,
        "statistics": {
            "total_builds": 0,
            "successful": 0,
            "failed": 0,
            "success_rate_percent": 0
        },
        "good_templates": [],
        "bad_templates": [],
        "errors": []
    }
}
JSONHEADER

    if command -v jq &>/dev/null; then
        local ended_at=$(date -Iseconds)
        local duration=$(($(date +%s) - START_TIME))
        local total=$((TOTAL_SUCCESS + TOTAL_FAILED))
        local rate=$((total > 0 ? TOTAL_SUCCESS * 100 / total : 0))

        # Build JSON arrays
        local good_json="["
        local first=true
        for key in "${!GOOD_TEMPLATES[@]}"; do
            if [[ "$first" == "true" ]]; then
                first=false
            else
                good_json+=","
            fi
            good_json+="\"$key\""
        done
        good_json+="]"

        local bad_json="["
        first=true
        for key in "${!BAD_TEMPLATES[@]}"; do
            if [[ "$first" == "true" ]]; then
                first=false
            else
                bad_json+=","
            fi
            bad_json+="{\"template\": \"$key\", \"error\": \"${BAD_TEMPLATES[$key]}\"}"
        done
        bad_json+="]"

        local errors_json="["
        first=true
        for key in "${!ERROR_GROUPS[@]}"; do
            if [[ "$first" == "true" ]]; then
                first=false
            else
                errors_json+=","
            fi
            local data="${ERROR_GROUPS[$key]}"
            local error_msg=$(echo "$data" | cut -d'|' -f1)
            local files=$(echo "$data" | cut -d'|' -f2-)
            errors_json+="{\"error\": \"$error_msg\", \"files\": ["
            local first_file=true
            echo "$files" | tr ',' '\n' | while read -r f; do
                if [[ "$first_file" == "true" ]]; then
                    first_file=false
                else
                    errors_json+=","
                fi
                errors_json+="\"$f\""
            done
            errors_json+="]}"
        done
        errors_json+="]"

        jq ".results.completed_at = \"$ended_at\"" "$temp_results" | \
        jq ".results.duration_seconds = $duration" | \
        jq ".results.statistics.total_builds = $total" | \
        jq ".results.statistics.successful = $TOTAL_SUCCESS" | \
        jq ".results.statistics.failed = $TOTAL_FAILED" | \
        jq ".results.statistics.success_rate_percent = $rate" | \
        jq ".results.good_templates = $good_json" | \
        jq ".results.bad_templates = $bad_json" | \
        jq ".results.errors = $errors_json" > "$RESULTS_FILE"
    else
        # Fallback without jq
        sed -i "s|""completed_at"".*|\"completed_at\": \"$(date -Iseconds)\",|" "$temp_results"
        echo "}" >> "$temp_results"

        # Write simple text fallback
        echo "Good templates:" > "$OUTPUT_DIR/good_templates.txt"
        for key in "${!GOOD_TEMPLATES[@]}"; do
            echo "  - $key" >> "$OUTPUT_DIR/good_templates.txt"
        done

        echo "Bad templates:" > "$OUTPUT_DIR/bad_templates.txt"
        for key in "${!BAD_TEMPLATES[@]}"; do
            echo "  - $key: ${BAD_TEMPLATES[$key]}" >> "$OUTPUT_DIR/bad_templates.txt"
        done

        mv "$temp_results" "$RESULTS_FILE"
    fi
}

# ============================================================
# Docker Registry Authentication
# ============================================================

get_registry_token() {
    local registry="${1:-registry-1.docker.io}"
    local token_file="/tmp/.docker_token_$$"

    # Check cache
    if [[ -n "${DOCKER_TOKEN[$registry]}" ]]; then
        echo "${DOCKER_TOKEN[$registry]}"
        return 0
    fi

    # Get token from Docker Hub
    local response
    response=$(curl -s --connect-timeout 5 --max-time 30 \
        -H "Accept: application/json" \
        "https://auth.docker.io/token?service=${registry}&scope=repository:library/*:pull" 2>&1)

    if [[ $? -eq 0 ]]; then
        local token=$(echo "$response" | grep -o '"token":"[^"]*"' | head -1 | cut -d'"' -f4)
        if [[ -n "$token" ]]; then
            DOCKER_TOKEN[$registry]="$token"
            echo "$token"
            return 0
        fi
    fi

    return 1
}

get_tags_authenticated() {
    local image="$1"
    local max_tags="${2:-10}"
    local token

    token=$(get_registry_token) || true

    local headers=()
    if [[ -n "$token" ]]; then
        headers=(-H "Authorization: Bearer $token")
    fi

    local result
    result=$(curl -s --connect-timeout 10 --max-time 60 \
        "${headers[@]}" \
        -H "Accept: application/json" \
        "https://hub.docker.com/v2/repositories/library/$image/tags?page_size=$max_tags&page_size=$TAG_PAGE_SIZE" 2>&1)

    local curl_exit=$?

    # Check for rate limiting
    if echo "$result" | grep -qiE "429|too many requests"; then
        wait_for_rate_limit
        token=$(get_registry_token) || true
        [[ -n "$token" ]] && headers=(-H "Authorization: Bearer $token")
        result=$(curl -s --connect-timeout 10 --max-time 60 \
            "${headers[@]}" \
            -H "Accept: application/json" \
            "https://hub.docker.com/v2/repositories/library/$image/tags?page_size=$max_tags")
    fi

    if [[ $curl_exit -ne 0 ]]; then
        return 1
    fi

    # Check for errors
    if echo "$result" | grep -qiE "error|not found|unauthorized"; then
        return 1
    fi

    # Check for v1 manifest
    if echo "$result" | grep -qiE "v1.*manifest|manifest unknown"; then
        return 1
    fi

    # Extract tag names
    echo "$result" | grep -o '"name":"[^"]*"' | head -n $max_tags | \
        sed 's/"name":"//;s/"//g' | grep -v "^$" | grep -v "^latest$"
}

# Cache for tags
declare -A TAGS_CACHE
CACHE_FILE="$OUTPUT_DIR/tags_cache.json"

load_tags_cache() {
    if [[ -f "$CACHE_FILE" ]]; then
        while IFS= read -r line; do
            local os=$(echo "$line" | cut -d: -f1)
            local tag=$(echo "$line" | cut -d: -f2-)
            TAGS_CACHE[$os]="${TAGS_CACHE[$os]}$tag"$'\n'
        done < <(cat "$CACHE_FILE" | grep -v '^{' | grep -v '^}')
    fi
}

save_tags_cache() {
    {
        echo "{"
        local first_os=true
        for os in "${!TAGS_CACHE[@]}"; do
            if [[ "$first_os" == "true" ]]; then
                first_os=false
            else
                echo ","
            fi
            echo -n "  \"$os\": ["
            local first_tag=true
            while IFS= read -r tag; do
                [[ -z "$tag" ]] && continue
                if [[ "$first_tag" == "true" ]]; then
                    first_tag=false
                else
                    echo -n ", "
                fi
                echo -n "\"$tag\""
            done <<< "${TAGS_CACHE[$os]}"
            echo "]"
        done
        echo "}"
    } > "$CACHE_FILE"
}

get_cached_tags() {
    local os="$1"

    if [[ -n "${TAGS_CACHE[$os]}" ]]; then
        echo "${TAGS_CACHE[$os]}"
        return 0
    fi

    return 1
}

# ============================================================
# Per-Distro Plugin Handlers
# ============================================================

# Base handler
handle_base() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
# hadolint ignore=DL3065,DL3060,DL3059
ARG IMAGE_TAG=${VERSION}
FROM ${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
}

# Alpine Handler
AlpineHandler() {
    local version="$1"
    local output_file="$2"

    # Determine Alpine version for repos
    local alpine_ver="3.19"
    case "$version" in
        3.2*) alpine_ver="3.18" ;;
        3.1*) alpine_ver="3.17" ;;
        edge) alpine_ver="edge" ;;
    esac

    cat > "$output_file" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
# hadolint ignore=DL3065
ARG IMAGE_TAG=${version}
FROM alpine:\${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS and repo fix
RUN echo 'hosts: files dns' > /etc/nsswitch.conf && \\
    echo "http://dl-cdn.alpinelinux.org/alpine/v${alpine_ver}/main" > /etc/apk/repositories && \\
    echo "http://dl-cdn.alpinelinux.org/alpine/v${alpine_ver}/community" >> /etc/apk/repositories && \\
    apk update

# Install OpenSSH
RUN apk add --no-cache openssh openssh-server openssh-client && \\
    ssh-keygen -A && \\
    mkdir -p /run/sshd /var/run

# Configure SSH
RUN echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \\
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# Ubuntu Handler
UbuntuHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
# hadolint ignore=DL3008,DL3065
ARG IMAGE_TAG=${version}
FROM ubuntu:\${IMAGE_TAG}

ARG SSH_PASSWORD
ENV DEBIAN_FRONTEND=noninteractive
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN apt-get update && \
    apt-get install -y --no-install-recommends openssh-server net-tools && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    rm -rf /var/lib/apt/lists/*

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# Debian Handler
DebianHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
# hadolint ignore=DL3008,DL3065
ARG IMAGE_TAG=${version}
FROM debian:\${IMAGE_TAG}

ARG SSH_PASSWORD
ENV DEBIAN_FRONTEND=noninteractive
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN apt-get update && \
    apt-get install -y --no-install-recommends openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    rm -rf /var/lib/apt/lists/*

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# Fedora Handler
FedoraHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM fedora:\${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    dnf clean all

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# Amazon Linux Handler
AmazonHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM amazonlinux:\${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN yum -y update && \
    yum -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    yum clean all

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# Oracle Linux Handler
OracleHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM oraclelinux:\${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    dnf clean all

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# Photon OS Handler
PhotonHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM photon:\${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN tdnf -y update && \
    tdnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    tdnf clean all

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# BusyBox Handler
BusyBoxHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
FROM busybox:${version}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# Install dropbear
RUN apk add --no-cache dropbear; \
    apk add --no-cache dropbear-dbclient dropbear-scp 2>/dev/null || true && \
    mkdir -p /etc/dropbear && \
    /usr/bin/dropbearkey -t rsa -f /etc/dropbear/dropbear_rsa_host_key

# Set password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/dropbear", "-F", "-w"]
EOF
}

# Cirros Handler
CirrosHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
FROM cirros:${version}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# Configure SSH
RUN echo "root:\${SSH_PASSWORD}" | chpasswd && \
    (sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config 2>/dev/null || true)

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# Mageia Handler
MageiaHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM mageia:\${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# Arch Linux Handler
ArchHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM archlinux:\${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN pacman -Sy --noconfirm && \
    pacman -S --noconfirm openssh && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    pacman -Scc --noconfirm

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# SL/openSUSE Handler
SLESHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM opensuse/leap:\${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN zypper -n update && \
    zypper -n install openssh && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    zypper clean -a

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# AlmaLinux Handler
AlmaHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM almalinux:\${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    dnf clean all

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# Rocky Linux Handler
RockyHandler() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM rockylinux:\${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    dnf clean all

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# Generate Dockerfile using plugin handler
generate_dockerfile() {
    local os="$1"
    local version="$2"
    local output_file="$3"

    local config="${OS_CONFIG[$os]}"
    local handler="${config##*|}"

    if declare -f "$handler" >/dev/null 2>&1; then
        "$handler" "$version" "$output_file"
    else
        handle_base "$version" "$output_file"
    fi
}

# ============================================================
# Core Functions
# ============================================================

init() {
    mkdir -p "$LOG_DIR" "$DOCKERFILE_DIR" "$OUTPUT_DIR"
    echo "" > "$LOG_DIR/build.log"
    load_tags_cache
    init_json_output
}

cleanup() {
    log INFO "Cleaning up..."
    jobs -p | xargs -r kill 2>/dev/null || true
    save_tags_cache
    write_results_json
}

log() {
    local level="$1"
    shift
    local msg="$*"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')

    case "$level" in
        INFO)
            echo -e "${BLUE}[INFO]${NC} $timestamp $msg"
            echo "[INFO] $timestamp $msg" >> "$LOG_DIR/build.log"
            ;;
        SUCCESS)
            echo -e "${GREEN}[SUCCESS]${NC} $timestamp $msg"
            echo "[SUCCESS] $timestamp $msg" >> "$LOG_DIR/build.log"
            ;;
        WARN)
            echo -e "${YELLOW}[WARN]${NC} $timestamp $msg"
            echo "[WARN] $timestamp $msg" >> "$LOG_DIR/build.log"
            ;;
        ERROR)
            echo -e "${RED}[ERROR]${NC} $timestamp $msg"
            echo "[ERROR] $timestamp $msg" >> "$LOG_DIR/build.log"
            ;;
        DEBUG)
            echo -e "${MAGENTA}[DEBUG]${NC} $timestamp $msg"
            echo "[DEBUG] $timestamp $msg" >> "$LOG_DIR/build.log"
            ;;
    esac
}

record_error() {
    local error_msg="$1"
    local file="$2"
    local key

    key=$(echo "$error_msg" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:] ' | tr ' ' '_' | cut -c1-50)

    if [[ -z "${ERROR_GROUPS[$key]}" ]]; then
        ERROR_GROUPS[$key]="$error_msg|$file"
    else
        ERROR_GROUPS[$key]="${ERROR_GROUPS[$key]},$file"
    fi
}

check_disk_space() {
    local available=$(df -m / | tail -1 | awk '{print $4}')
    log DEBUG "Available disk space: ${available}MB"

    if [[ $available -lt $SPACE_THRESHOLD_MB ]]; then
        log WARN "Low disk space (${available}MB), attempting cleanup..."

        docker system prune -af --volumes 2>/dev/null || true
        apt-get clean 2>/dev/null || true
        rm -rf /var/cache/apt/archives/* 2>/dev/null || true
        rm -rf /tmp/* 2>/dev/null || true

        available=$(df -m / | tail -1 | awk '{print $4}')
        log INFO "After cleanup: ${available}MB available"
    fi
}

wait_for_rate_limit() {
    log WARN "Rate limit detected, waiting ${RATE_LIMIT_BACKOFF} seconds..."
    sleep $RATE_LIMIT_BACKOFF
}

parse_build_error() {
    local output="$1"
    local file="$2"

    if echo "$output" | grep -qiE "429|too many requests|rate.limit|exceeded.*limit"; then
        wait_for_rate_limit
        record_error "Docker Hub rate limit" "$file"
        return 1
    fi

    if echo "$output" | grep -qiE "connection reset|ECONNRESET|connection refused"; then
        record_error "Connection reset by peer" "$file"
        RETRY_DELAY=5
        return 2
    fi

    if echo "$output" | grep -qiE "tls handshake|handshake timeout|ssl|tls"; then
        record_error "TLS/SSL handshake timeout" "$file"
        return 2
    fi

    if echo "$output" | grep -qiE "dial tcp|dial timeout|connection timeout|no route to host"; then
        record_error "TCP connection timeout" "$file"
        RETRY_DELAY=15
        return 2
    fi

    if echo "$output" | grep -qiE "i/o timeout|io timeout|read timeout"; then
        record_error "I/O timeout" "$file"
        return 2
    fi

    if echo "$output" | grep -qiE "deadline exceeded|context deadline|context canceled"; then
        record_error "Deadline exceeded" "$file"
        return 2
    fi

    if echo "$output" | grep -qiE "no space|ENOSPC|disk full|disk quota"; then
        check_disk_space
        record_error "Disk space error" "$file"
        return 2
    fi

    if echo "$output" | grep -qiE "network|dns|resolve|could not resolve"; then
        record_error "DNS/Network resolution error" "$file"
        return 2
    fi

    if echo "$output" | grep -qiE "v1.*manifest|manifest unknown"; then
        record_error "v1 manifest not supported" "$file"
        return 3
    fi

    if echo "$output" | grep -qiE "unauthorized|authentication|denied|access denied"; then
        record_error "Authentication/access denied" "$file"
        return 3
    fi

    # Check for broken repos
    if echo "$output" | grep -qiE "vault.centos.org|mirrorlist|repo.*expired|404.*not found"; then
        record_error "Broken/missing repository" "$file"
        return 2
    fi

    return 0
}

get_tags() {
    local image="$1"
    local max_tags="${2:-$MAX_TAGS_PER_OS}"

    # Check cache first
    if get_cached_tags "$image" >/dev/null; then
        get_cached_tags "$image" | head -n "$max_tags"
        return 0
    fi

    # Fetch from registry
    local tags
    tags=$(get_tags_authenticated "$image" "$max_tags")

    if [[ -n "$tags" ]]; then
        TAGS_CACHE[$image]="$tags"
        echo "$tags"
        return 0
    fi

    return 1
}

build_image() {
    local os="$1"
    local version="$2"
    local dockerfile_path="$3"
    local image_tag="$4"
    local log_file="$5"

    export DOCKER_BUILDKIT=1
    export COMPOSE_DOCKER_CLI_BUILD=1

    local build_output
    build_output=$(docker build \
        --no-cache \
        --progress=plain \
        --build-arg VERSION="$version" \
        --build-arg SSH_PASSWORD="$SSH_PASSWORD" \
        -t "$image_tag" \
        -f "$dockerfile_path" \
        "$(dirname "$dockerfile_path")" \
        2>&1 | tee "$log_file")

    local exit_code=${PIPESTATUS[0]}

    if [[ $exit_code -ne 0 ]]; then
        parse_build_error "$build_output" "$dockerfile_path"
    fi

    return $exit_code
}

test_ssh() {
    local container_id="$1"
    local max_attempts=10

    for ((i=1; i<=max_attempts; i++)); do
        if ! docker ps -q --filter "id=$container_id" | grep -q .; then
            log ERROR "Container $container_id is not running"
            return 1
        fi

        local result
        result=$(docker exec "$container_id" sh -c "nc -z localhost 22 2>/dev/null && echo 'OPEN' || echo 'CLOSED'" 2>/dev/null)

        if echo "$result" | grep -q "OPEN"; then
            log DEBUG "SSH port is open"
            return 0
        fi

        log DEBUG "SSH not ready (attempt $i/$max_attempts), waiting..."
        sleep 3
    done

    if docker exec "$container_id" pgrep sshd >/dev/null 2>&1 || \
       docker exec "$container_id" pgrep dropbear >/dev/null 2>&1; then
        log DEBUG "SSH process found running"
        return 0
    fi

    log ERROR "SSH test failed after $max_attempts attempts"
    return 1
}

process_build() {
    local os="$1"
    local version="$2"

    local safe_version=$(echo "$version" | tr '/' '-' | tr ':' '-')
    local image_tag="ssh-$os:$safe_version"
    local dockerfile_path="$DOCKERFILE_DIR/${os}_${safe_version}.dockerfile"
    local log_file="$LOG_DIR/${os}_${safe_version}.log"

    BUILD_STATUS[$os:$version]="building"
    CURRENT_BUILD="$os:$version"
    TOTAL_PENDING=$((TOTAL_PENDING - 1))
    TOTAL_PENDING=$((TOTAL_PENDING < 0 ? 0 : TOTAL_PENDING))

    log INFO "Processing: $os:$version"
    update_tui

    check_disk_space

    if docker image inspect "$image_tag" >/dev/null 2>&1; then
        log INFO "Image $image_tag already exists, removing for rebuild..."
        docker rmi "$image_tag" 2>/dev/null || true
    fi

    local build_success=false
    for ((attempt=1; attempt<=MAX_RETRIES; attempt++)); do
        log INFO "Build attempt $attempt/$MAX_RETRIES"

        if build_image "$os" "$version" "$dockerfile_path" "$image_tag" "$log_file"; then
            build_success=true
            break
        fi

        local exit_code=$?

        if [[ $exit_code -eq 3 ]]; then
            break
        fi

        if [[ $attempt -lt $MAX_RETRIES ]]; then
            log WARN "Build failed, retrying in $RETRY_DELAY seconds..."
            sleep $RETRY_DELAY
            RETRY_DELAY=$((RETRY_DELAY * 2))
        fi
    done

    if [[ "$build_success" != "true" ]]; then
        log ERROR "Build failed for $os:$version"
        BUILD_STATUS[$os:$version]="failed"
        BUILD_ERRORS[$os:$version]="Build failed - see $log_file"
        BAD_TEMPLATES["$os:$version"]="Build failed - see $log_file"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
        record_error "Build failed permanently" "$dockerfile_path"
        append_build_to_json "$os" "$version" "failed" "Build failed"
        update_tui
        return 1
    fi

    log INFO "Starting container for SSH test..."
    local container_id
    container_id=$(docker run -d --rm -p 2222:22 "$image_tag" 2>&1)

    if [[ $? -ne 0 ]] || echo "$container_id" | grep -qiE "error|failed"; then
        log ERROR "Failed to start container: $container_id"
        BUILD_STATUS[$os:$version]="failed"
        BUILD_ERRORS[$os:$version]="Container start failed"
        BAD_TEMPLATES["$os:$version"]="Container start failed"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
        record_error "Container start failed" "$dockerfile_path"
        append_build_to_json "$os" "$version" "failed" "Container start failed"
        update_tui
        return 1
    fi

    sleep 5

    if test_ssh "$container_id"; then
        BUILD_STATUS[$os:$version]="success"
        GOOD_TEMPLATES["$os:$version"]="$image_tag"
        TOTAL_SUCCESS=$((TOTAL_SUCCESS + 1))
        log SUCCESS "Successfully built and tested $os:$version"
        append_build_to_json "$os" "$version" "success"
    else
        BUILD_STATUS[$os:$version]="failed"
        BUILD_ERRORS[$os:$version]="SSH test failed"
        BAD_TEMPLATES["$os:$version"]="SSH test failed"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
        record_error "SSH test failed" "$dockerfile_path"
        append_build_to_json "$os" "$version" "failed" "SSH test failed"
    fi

    docker stop "$container_id" 2>/dev/null || true
    CURRENT_BUILD=""
    update_tui

    return 0
}

process_parallel() {
    local -n queue=$1
    local pids=()

    while [[ ${#queue[@]} -gt 0 ]] || [[ ${#pids[@]} -gt 0 ]]; do
        while [[ ${#pids[@]} -lt $PARALLELISM ]] && [[ ${#queue[@]} -gt 0 ]]; do
            local item="${queue[0]}"
            queue=("${queue[@]:1}")

            local os=$(echo "$item" | cut -d: -f1)
            local version=$(echo "$item" | cut -d: -f2-)

            (
                process_build "$os" "$version"
            ) &

            pids+=($!)
            sleep 0.5
        done

        local new_pids=()
        for ((i=0; i<${#pids[@]}; i++)); do
            if kill -0 ${pids[$i]} 2>/dev/null; then
                new_pids+=(${pids[$i]})
            else
                wait ${pids[$i]} || true
            fi
        done
        pids=("${new_pids[@]}")

        update_tui
        sleep 2
    done
}

main() {
    init
    init_tui

    log INFO "=============================================="
    log INFO "Docker SSH Image Builder v${VERSION}"
    log INFO "=============================================="
    log INFO "Parallelism: $PARALLELISM"
    log INFO "SSH Password: ********"
    log INFO "Log Directory: $LOG_DIR"
    log INFO "Dockerfile Directory: $DOCKERFILE_DIR"
    log INFO "=============================================="

    declare -a build_queue=()

    # Fetch tags for each OS
    for os in "${ALL_OS[@]}"; do
        log INFO "Fetching tags for $os..."

        local tags
        tags=$(get_tags "$os" "$MAX_TAGS_PER_OS")

        if [[ -z "$tags" ]]; then
            log WARN "No tags found for $os, skipping..."
            continue
        fi

        local count=0
        while IFS= read -r version && [[ $count -lt $MAX_TAGS_PER_OS ]]; do
            if [[ -n "$version" ]]; then
                build_queue+=("$os:$version")
                BUILD_STATUS[$os:$version]="pending"
                count=$((count + 1))
                TOTAL_PENDING=$((TOTAL_PENDING + 1))
            fi
        done <<< "$tags"

        log INFO "Added $count versions of $os to build queue"
    done

    local total_items=${#build_queue[@]}
    log INFO "Total items to build: $total_items"

    update_tui

    # Process queue
    process_parallel build_queue

    # Final summary
    display_final_summary
    restore_tui
}

display_final_summary() {
    clear
    echo ""
    echo "=============================================="
    echo -e "${CYAN}FINAL SUMMARY${NC}"
    echo "=============================================="
    echo ""
    echo "Total Success: $TOTAL_SUCCESS"
    echo "Total Failed: $TOTAL_FAILED"
    echo "Duration: $(format_duration $(($(date +%s) - START_TIME)))"
    echo ""

    echo "Good Templates (${#GOOD_TEMPLATES[@]}):"
    for key in "${!GOOD_TEMPLATES[@]}"; do
        echo -e "  ${GREEN}✓${NC} $key"
    done

    echo ""
    echo "Bad Templates (${#BAD_TEMPLATES[@]}):"
    for key in "${!BAD_TEMPLATES[@]}"; do
        echo -e "  ${RED}✗${NC} $key: ${BAD_TEMPLATES[$key]}"
    done

    if [[ ${#ERROR_GROUPS[@]} -gt 0 ]]; then
        echo ""
        echo "=============================================="
        echo "ERROR SUMMARY (Grouped by Error Type)"
        echo "=============================================="
        local count=1
        for key in "${!ERROR_GROUPS[@]}"; do
            local data="${ERROR_GROUPS[$key]}"
            local error_msg=$(echo "$data" | cut -d'|' -f1)
            local files=$(echo "$data" | cut -d'|' -f2-)

            echo ""
            echo "Error #$count: $error_msg"
            echo "  Affected files: $files"
            count=$((count + 1))
        done
    fi

    echo ""
    echo "=============================================="
    echo "Results saved to:"
    echo "  - $RESULTS_FILE"
    echo "  - $OUTPUT_DIR/good_templates.txt"
    echo "  - $OUTPUT_DIR/bad_templates.txt"
    echo "=============================================="
}

# Export functions for subshells
export -f log record_error check_disk_space wait_for_rate_limit
export -f parse_build_error get_tags get_tags_authenticated get_registry_token
export -f build_image test_ssh process_build generate_dockerfile
export -f AlpineHandler UbuntuHandler DebianHandler FedoraHandler AmazonHandler
export -f OracleHandler PhotonHandler BusyBoxHandler CirrosHandler MageiaHandler
export -f ArchHandler SLESHandler AlmaHandler RockyHandler handle_base
export -f update_tui draw_header draw_summary_panel draw_current_build
export -f draw_build_grid draw_error_summary draw_footer format_duration
export -f append_build_to_json

export SSH_PASSWORD LOG_DIR DOCKERFILE_DIR OUTPUT_DIR PARALLELISM
export -A BUILD_STATUS BUILD_ERRORS ERROR_GROUPS GOOD_TEMPLATES BAD_TEMPLATES
export -A TAGS_CACHE DOCKER_TOKEN ALL_OS
export TOTAL_SUCCESS TOTAL_FAILED TOTAL_PENDING START_TIME CURRENT_BUILD

main "$@"
