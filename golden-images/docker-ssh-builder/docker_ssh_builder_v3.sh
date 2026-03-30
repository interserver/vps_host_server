#!/bin/bash
#
# Docker SSH Image Builder v3 - Fixed Tag Fetching
#

set -o pipefail

# Configuration
PARALLELISM="${PARALLELISM:-6}"
SSH_PASSWORD="InterServer!23"
LOG_DIR="/workspace/docker-ssh-builder/logs"
DOCKERFILE_DIR="/workspace/docker-ssh-builder/dockerfiles"
OUTPUT_DIR="/workspace/docker-ssh-builder/output"
MAX_RETRIES=3
RETRY_DELAY=10
RATE_LIMIT_BACKOFF=120

# OS List
ALL_OS=(
    "busybox" "ubuntu" "fedora" "debian" "cirros" "mageia"
    "oraclelinux" "alpine" "photon" "amazonlinux" "almalinux"
    "rockylinux" "sl" "archlinux"
)

# Global state
declare -A GOOD_TEMPLATES
declare -A BAD_TEMPLATES
declare -A ERROR_GROUPS
TOTAL_SUCCESS=0
TOTAL_FAILED=0
START_TIME=$(date +%s)

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'

init() {
    mkdir -p "$LOG_DIR" "$DOCKERFILE_DIR" "$OUTPUT_DIR"
    echo "" > "$LOG_DIR/build.log"
}

log() {
    local level="$1"
    shift
    local msg="$*"
    echo -e "${BLUE}[$level]${NC} $(date '+%Y-%m-%d %H:%M:%S') $msg"
    echo "[$level] $(date '+%Y-%m-%d %H:%M:%S') $msg" >> "$LOG_DIR/build.log"
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

print_error_summary() {
    echo ""
    log "INFO" "=============================================="
    log "INFO" "ERROR SUMMARY (Grouped by Error Type)"
    log "INFO" "=============================================="
    local count=1
    for key in "${!ERROR_GROUPS[@]}"; do
        local data="${ERROR_GROUPS[$key]}"
        local error_msg=$(echo "$data" | cut -d'|' -f1)
        local files=$(echo "$data" | cut -d'|' -f2-)
        echo ""
        log "ERROR" "Error #$count: $error_msg"
        log "ERROR" "  Affected files: $files"
        count=$((count + 1))
    done
}

check_disk_space() {
    local available=$(df -m / | tail -1 | awk '{print $4}')
    log "DEBUG" "Available disk space: ${available}MB"
    if [[ $available -lt 5000 ]]; then
        log "WARN" "Low disk space (${available}MB), cleaning..."
        docker system prune -af --volumes 2>/dev/null || true
        rm -rf /tmp/* 2>/dev/null || true
    fi
}

wait_for_rate_limit() {
    log "WARN" "Rate limit detected, waiting ${RATE_LIMIT_BACKOFF} seconds..."
    sleep $RATE_LIMIT_BACKOFF
}

parse_build_error() {
    local output="$1"
    local file="$2"

    if echo "$output" | grep -qiE "429|too many requests|rate.limit"; then
        wait_for_rate_limit
        record_error "Docker Hub rate limit" "$file"
        return 1
    fi
    if echo "$output" | grep -qiE "connection reset|ECONNRESET"; then
        record_error "Connection reset" "$file"
        return 2
    fi
    if echo "$output" | grep -qiE "tls handshake|handshake timeout"; then
        record_error "TLS handshake timeout" "$file"
        return 2
    fi
    if echo "$output" | grep -qiE "dial tcp|dial timeout"; then
        record_error "TCP timeout" "$file"
        return 2
    fi
    if echo "$output" | grep -qiE "i/o timeout|io timeout"; then
        record_error "I/O timeout" "$file"
        return 2
    fi
    if echo "$output" | grep -qiE "no space|ENOSPC"; then
        check_disk_space
        record_error "Disk space error" "$file"
        return 2
    fi
    if echo "$output" | grep -qiE "v1.*manifest|manifest unknown"; then
        record_error "v1 manifest not supported" "$file"
        return 3
    fi
    return 0
}

# FIXED: Proper Docker Hub tag fetching
get_tags() {
    local image="$1"
    local max_tags="${2:-10}"

    log "DEBUG" "Fetching tags for $image from Docker Hub..."

    local result
    result=$(curl -s --connect-timeout 10 --max-time 60 \
        "https://hub.docker.com/v2/repositories/library/$image/tags?page_size=$max_tags" 2>&1)

    local curl_exit=$?

    if [[ $curl_exit -ne 0 ]]; then
        log "WARN" "curl failed for $image (exit: $curl_exit): $result"
        return 1
    fi

    # Check for rate limiting
    if echo "$result" | grep -qiE "429|too many requests"; then
        wait_for_rate_limit
        result=$(curl -s --connect-timeout 10 --max-time 60 \
            "https://hub.docker.com/v2/repositories/library/$image/tags?page_size=$max_tags")
    fi

    # Check for errors
    if echo "$result" | grep -qiE '"errors"|"error"' && ! echo "$result" | grep -q '"results"'; then
        log "WARN" "API error for $image: $result"
        return 1
    fi

    # FIXED: Extract tags using proper pattern matching
    # The API returns: "name": "tagname"
    local tags=$(echo "$result" | grep -o '"name"[[:space:]]*:[[:space:]]*"[^"]*"' | \
                 sed 's/"name"[[:space:]]*:[[:space:]]*"//;s/"$//' | head -n "$max_tags")

    if [[ -z "$tags" ]]; then
        log "WARN" "No tags extracted for $image"
        # Debug: show first 200 chars of response
        log "DEBUG" "Response preview: ${result:0:200}"
        return 1
    fi

    echo "$tags"
    return 0
}

build_image() {
    local os="$1"
    local version="$2"
    local dockerfile_path="$3"
    local image_tag="$4"
    local log_file="$5"

    export DOCKER_BUILDKIT=1

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

    for ((i=1; i<=10; i++)); do
        if ! docker ps -q --filter "id=$container_id" | grep -q .; then
            log "ERROR" "Container $container_id stopped"
            return 1
        fi

        local result
        result=$(docker exec "$container_id" sh -c "nc -z localhost 22 2>/dev/null && echo 'OPEN'" 2>/dev/null)

        if echo "$result" | grep -q "OPEN"; then
            return 0
        fi

        sleep 3
    done

    # Try process check
    if docker exec "$container_id" pgrep -x sshd >/dev/null 2>&1 || \
       docker exec "$container_id" pgrep -x dropbear >/dev/null 2>&1; then
        return 0
    fi

    return 1
}

process_build() {
    local os="$1"
    local version="$2"

    local safe_version=$(echo "$version" | tr '/' '-' | tr ':' '-')
    local image_tag="ssh-$os:$safe_version"
    local dockerfile_path="$DOCKERFILE_DIR/${os}_${safe_version}.dockerfile"
    local log_file="$LOG_DIR/${os}_${safe_version}.log"

    log "INFO" "=============================================="
    log "INFO" "Processing: $os:$version"

    check_disk_space

    # Remove existing image
    docker rmi "$image_tag" 2>/dev/null || true

    # Build with retry
    local build_success=false
    for ((attempt=1; attempt<=MAX_RETRIES; attempt++)); do
        log "INFO" "Build attempt $attempt/$MAX_RETRIES"

        if build_image "$os" "$version" "$dockerfile_path" "$image_tag" "$log_file"; then
            build_success=true
            break
        fi

        local exit_code=$?
        if [[ $exit_code -eq 3 ]]; then
            break
        fi

        if [[ $attempt -lt $MAX_RETRIES ]]; then
            log "WARN" "Retrying in $RETRY_DELAY seconds..."
            sleep $RETRY_DELAY
            RETRY_DELAY=$((RETRY_DELAY * 2))
        fi
    done

    if [[ "$build_success" != "true" ]]; then
        log "ERROR" "Build failed for $os:$version"
        BAD_TEMPLATES["$os:$version"]="Build failed"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
        record_error "Build failed" "$dockerfile_path"
        return 1
    fi

    # Test SSH
    log "INFO" "Starting container for SSH test..."
    local container_id
    container_id=$(docker run -d --rm -p 2222:22 "$image_tag" 2>&1)

    if [[ $? -ne 0 ]]; then
        log "ERROR" "Failed to start container: $container_id"
        BAD_TEMPLATES["$os:$version"]="Container start failed"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
        return 1
    fi

    sleep 5

    if test_ssh "$container_id"; then
        GOOD_TEMPLATES["$os:$version"]="$image_tag"
        TOTAL_SUCCESS=$((TOTAL_SUCCESS + 1))
        log "SUCCESS" "Successfully built and tested $os:$version"
    else
        BAD_TEMPLATES["$os:$version"]="SSH test failed"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
        record_error "SSH test failed" "$dockerfile_path"
    fi

    docker stop "$container_id" 2>/dev/null || true
    return 0
}

# Generate Dockerfiles
generate_dockerfile() {
    local os="$1"
    local version="$2"
    local output_file="$3"

    case "$os" in
        alpine)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG VERSION
FROM alpine:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN echo 'hosts: files dns' > /etc/nsswitch.conf && \
    apk update

# Install OpenSSH
RUN apk add --no-cache openssh openssh-server && \
    ssh-keygen -A && \
    mkdir -p /run/sshd

# Configure SSH
RUN echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        ubuntu)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG VERSION
FROM ubuntu:${VERSION}

ARG SSH_PASSWORD
ENV DEBIAN_FRONTEND=noninteractive
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN rm -f /etc/resolv.conf && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN apt-get update && \
    apt-get install -y --no-install-recommends openssh-server && \
    mkdir -p /run/sshd && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config && \
    rm -rf /var/lib/apt/lists/*

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        debian)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG VERSION
FROM debian:${VERSION}

ARG SSH_PASSWORD
ENV DEBIAN_FRONTEND=noninteractive
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN rm -f /etc/resolv.conf && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN apt-get update && \
    apt-get install -y --no-install-recommends openssh-server && \
    mkdir -p /run/sshd && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config && \
    rm -rf /var/lib/apt/lists/*

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        fedora)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG VERSION
FROM fedora:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN rm -f /etc/resolv.conf && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        amazonlinux)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG VERSION
FROM amazonlinux:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN rm -f /etc/resolv.conf && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN yum -y update && \
    yum -y install openssh-server && \
    mkdir -p /run/sshd && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        oraclelinux)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG VERSION
FROM oraclelinux:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN rm -f /etc/resolv.conf && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        photon)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG VERSION
FROM photon:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN rm -f /etc/resolv.conf && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN tdnf -y update && \
    tdnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        busybox)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
FROM busybox:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# Install dropbear
RUN apk add --no-cache dropbear && \
    mkdir -p /etc/dropbear && \
    /usr/bin/dropbearkey -t rsa -f /etc/dropbear/dropbear_rsa_host_key

# Set password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/dropbear", "-F", "-w"]
EOF
            ;;
        cirros)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
FROM cirros:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        mageia)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG VERSION
FROM mageia:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

RUN rm -f /etc/resolv.conf && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

RUN dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        archlinux)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG VERSION
FROM archlinux:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

RUN rm -f /etc/resolv.conf && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

RUN pacman -Sy --noconfirm && \
    pacman -S --noconfirm openssh && \
    mkdir -p /run/sshd && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        sl|opensuse)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG VERSION
FROM opensuse/leap:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

RUN rm -f /etc/resolv.conf && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

RUN zypper -n update && \
    zypper -n install openssh && \
    mkdir -p /run/sshd && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        almalinux)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG VERSION
FROM almalinux:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

RUN rm -f /etc/resolv.conf && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        rockylinux)
            cat > "$output_file" << 'EOF'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG VERSION
FROM rockylinux:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

RUN rm -f /etc/resolv.conf && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
            ;;
        *)
            log "ERROR" "Unknown OS: $os"
            return 1
            ;;
    esac
}

main() {
    init

    echo ""
    echo "=============================================="
    echo -e "${CYAN}Docker SSH Image Builder v3${NC}"
    echo "=============================================="
    echo "Parallelism: $PARALLELISM"
    echo "SSH Password: ********"
    echo "=============================================="
    echo ""

    declare -a build_queue=()

    # Get tags for each OS
    for os in "${ALL_OS[@]}"; do
        log "INFO" "Fetching tags for $os..."

        local tags
        tags=$(get_tags "$os" 10)

        if [[ -z "$tags" ]]; then
            log "WARN" "No tags found for $os, skipping..."
            continue
        fi

        local count=0
        while IFS= read -r version && [[ $count -lt 10 ]]; do
            if [[ -n "$version" ]]; then
                build_queue+=("$os:$version")
                count=$((count + 1))
            fi
        done <<< "$tags"

        log "INFO" "Added $count versions of $os to build queue"
    done

    local total_items=${#build_queue[@]}
    log "INFO" "Total items to build: $total_items"

    if [[ $total_items -eq 0 ]]; then
        log "ERROR" "No images to build. Check Docker Hub API access."
        exit 1
    fi

    # Process queue with parallelism
    local pids=()
    local index=0

    while [[ $index -lt $total_items ]] || [[ ${#pids[@]} -gt 0 ]]; do
        # Start new jobs
        while [[ ${#pids[@]} -lt $PARALLELISM ]] && [[ $index -lt $total_items ]]; do
            local item="${build_queue[$index]}"
            index=$((index + 1))

            local os=$(echo "$item" | cut -d: -f1)
            local version=$(echo "$item" | cut -d: -f2-)

            (
                process_build "$os" "$version"
            ) &

            pids+=($!)
            sleep 0.5
        done

        # Check for completed jobs
        local new_pids=()
        for ((i=0; i<${#pids[@]}; i++)); do
            if kill -0 ${pids[$i]} 2>/dev/null; then
                new_pids+=(${pids[$i]})
            else
                wait ${pids[$i]} || true
            fi
        done
        pids=("${new_pids[@]}")

        # Show progress
        echo -ne "\rProgress: $((index - ${#pids[@]}))/$total_items (Success: $TOTAL_SUCCESS, Failed: $TOTAL_FAILED)    "

        sleep 2
    done

    echo ""
    echo ""

    # Final summary
    echo "=============================================="
    echo -e "${CYAN}FINAL SUMMARY${NC}"
    echo "=============================================="
    echo "Total Success: $TOTAL_SUCCESS"
    echo "Total Failed: $TOTAL_FAILED"
    echo ""

    echo "Good Templates (${#GOOD_TEMPLATES[@]}):"
    for key in "${!GOOD_TEMPLATES[@]}"; do
        echo -e "  ${GREEN}✓${NC} $key"
    done

    echo ""
    echo "Bad Templates (${#BAD_TEMPLATES[@]}):"
    for key in "${!BAD_TEMPLATES[@]}"; do
        echo -e "  ${RED}✗${NC} $key"
    done

    if [[ ${#ERROR_GROUPS[@]} -gt 0 ]]; then
        print_error_summary
    fi

    # Save results
    {
        echo "# Good templates"
        for key in "${!GOOD_TEMPLATES[@]}"; do
            echo "$key"
        done
    } > "$OUTPUT_DIR/good_templates.txt"

    {
        echo "# Bad templates"
        for key in "${!BAD_TEMPLATES[@]}"; do
            echo "$key"
        done
    } > "$OUTPUT_DIR/bad_templates.txt"

    echo ""
    echo "=============================================="
    echo "Results saved to:"
    echo "  - $OUTPUT_DIR/good_templates.txt"
    echo "  - $OUTPUT_DIR/bad_templates.txt"
    echo "=============================================="
}

main "$@"
