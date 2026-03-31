#!/bin/bash
#
# Enhanced Docker SSH Image Builder with advanced error handling
# Features: Retry logic, exponential backoff, better parallel execution
#

set -o pipefail
set -o errtrace

# Trap for cleanup
trap 'cleanup' EXIT INT TERM

# Configuration
PARALLELISM="${PARALLELISM:-6}"
SSH_PASSWORD="InterServer!23"
LOG_DIR="/workspace/docker-ssh-builder/logs"
DOCKERFILE_DIR="/workspace/docker-ssh-builder/dockerfiles"
OUTPUT_DIR="/workspace/docker-ssh-builder/output"
SPACE_THRESHOLD_MB=5000
MAX_RETRIES=3
RETRY_DELAY=10
RATE_LIMIT_BACKOFF=120

# OS List
declare -a ALL_OS=(
    "busybox" "ubuntu" "fedora" "debian" "cirros" "mageia"
    "oraclelinux" "alpine" "photon" "amazonlinux" "almalinux"
    "rockylinux" "sl" "archlinux"
)

# Global state
declare -A GOOD_TEMPLATES
declare -A BAD_TEMPLATES
declare -A ERROR_GROUPS
declare -a BUILD_QUEUE
declare -A ACTIVE_BUILDS
TOTAL_SUCCESS=0
TOTAL_FAILED=0
START_TIME=$(date +%s)

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m'

# Initialize
init() {
    mkdir -p "$LOG_DIR" "$DOCKERFILE_DIR" "$OUTPUT_DIR"
    echo "" > "$LOG_DIR/build.log"
    echo "" > "$OUTPUT_DIR/good_templates.txt"
    echo "" > "$OUTPUT_DIR/bad_templates.txt"
}

# Cleanup function
cleanup() {
    log_info "Cleaning up..."
    # Kill any remaining background jobs
    jobs -p | xargs -r kill 2>/dev/null || true
}

# Logging
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

# Record error for grouping
record_error() {
    local error_msg="$1"
    local file="$2"
    local key

    # Create a normalized key from the error message
    key=$(echo "$error_msg" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:] ' | tr ' ' '_' | cut -c1-50)

    if [[ -z "${ERROR_GROUPS[$key]}" ]]; then
        ERROR_GROUPS[$key]="$error_msg|$file"
    else
        ERROR_GROUPS[$key]="${ERROR_GROUPS[$key]},$file"
    fi
}

# Print error summary
print_error_summary() {
    echo ""
    log INFO "=============================================="
    log INFO "ERROR SUMMARY (Grouped by Error Type)"
    log INFO "=============================================="

    local count=1
    for key in "${!ERROR_GROUPS[@]}"; do
        local data="${ERROR_GROUPS[$key]}"
        local error_msg=$(echo "$data" | cut -d'|' -f1)
        local files=$(echo "$data" | cut -d'|' -f2-)

        echo ""
        log ERROR "Error #$count: $error_msg"
        log ERROR "  Affected files: $files"
        count=$((count + 1))
    done
}

# Check and free disk space
check_disk_space() {
    local available=$(df -m / | tail -1 | awk '{print $4}')
    log DEBUG "Available disk space: ${available}MB"

    if [[ $available -lt $SPACE_THRESHOLD_MB ]]; then
        log WARN "Low disk space (${available}MB), attempting cleanup..."

        # Docker cleanup
        docker system prune -af --volumes 2>/dev/null || true

        # Clean apt cache
        apt-get clean 2>/dev/null || true
        rm -rf /var/cache/apt/archives/* 2>/dev/null || true

        # Clean temp files
        rm -rf /tmp/* 2>/dev/null || true

        available=$(df -m / | tail -1 | awk '{print $4}')
        log INFO "After cleanup: ${available}MB available"
    fi
}

# Retry wrapper
retry() {
    local max_attempts=$1
    shift
    local cmd="$@"
    local attempt=1

    while [[ $attempt -le $max_attempts ]]; do
        log DEBUG "Attempt $attempt of $max_attempts: $cmd"

        if eval "$cmd"; then
            return 0
        fi

        local exit_code=$?

        # Check if error is retryable
        if [[ $exit_code -eq 0 ]]; then
            return 0
        fi

        if [[ $attempt -lt $max_attempts ]]; then
            log WARN "Command failed with exit code $exit_code, retrying in $RETRY_DELAY seconds..."
            sleep $RETRY_DELAY
            RETRY_DELAY=$((RETRY_DELAY * 2))  # Exponential backoff
        fi

        attempt=$((attempt + 1))
    done

    log ERROR "Command failed after $max_attempts attempts: $cmd"
    return $exit_code
}

# Wait for rate limit
wait_for_rate_limit() {
    log WARN "Rate limit detected, waiting ${RATE_LIMIT_BACKOFF} seconds..."
    sleep $RATE_LIMIT_BACKOFF
}

# Parse build error
parse_build_error() {
    local output="$1"
    local file="$2"

    # Rate limiting
    if echo "$output" | grep -qiE "429|too many requests|rate.limit|exceeded.*limit"; then
        wait_for_rate_limit
        record_error "Docker Hub rate limit" "$file"
        return 1
    fi

    # Connection errors
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

    if echo "$output" | grep -qiE "network|dns|resolve|could not resolve| name or service not known"; then
        record_error "DNS/Network resolution error" "$file"
        return 2
    fi

    if echo "$output" | grep -qiE "v1.*manifest|manifest unknown|manifest v2"; then
        record_error "v1 manifest not supported" "$file"
        return 3  # Non-retryable
    fi

    if echo "$output" | grep -qiE "unauthorized|authentication|denied|access denied"; then
        record_error "Authentication/access denied" "$file"
        return 3
    fi

    return 0
}

# Get Docker Hub tags
get_tags() {
    local image="$1"
    local max_tags=10

    local result
    result=$(curl -s --connect-timeout 10 --max-time 30 \
        "https://hub.docker.com/v2/repositories/library/$image/tags?page_size=$max_tags&page=1" 2>&1)

    local curl_exit=$?

    if [[ $curl_exit -ne 0 ]]; then
        log WARN "Failed to fetch tags for $image (curl exit: $curl_exit)"
        echo ""
        return 1
    fi

    # Check for errors
    if echo "$result" | grep -qiE "429|too many requests"; then
        wait_for_rate_limit
        result=$(curl -s --connect-timeout 10 --max-time 30 \
            "https://hub.docker.com/v2/repositories/library/$image/tags?page_size=$max_tags&page=1")
    fi

    if echo "$result" | grep -qiE "error|not found|unauthorized"; then
        log WARN "Error response for $image"
        echo ""
        return 1
    fi

    # Check for v1 manifest
    if echo "$result" | grep -qiE "v1.*manifest|manifest unknown"; then
        log WARN "Skipping $image - v1 manifest only"
        echo ""
        return 1
    fi

    # Extract tag names
    echo "$result" | grep -o '"name":"[^"]*"' | head -n $max_tags | \
        sed 's/"name":"//;s/"//g' | grep -v "^$" | grep -v "latest"
}

# Check if image needs to be rebuilt
should_skip() {
    local image_tag="$1"
    local dockerfile_path="$2"

    # Always rebuild with our custom configuration
    return 1
}

# Build Docker image
build_image() {
    local os="$1"
    local version="$2"
    local dockerfile_path="$3"
    local image_tag="$4"
    local log_file="$5"

    export DOCKER_BUILDKIT=1
    export COMPOSE_DOCKER_CLI_BUILD=1

    local build_args="--build-arg VERSION=$version --build-arg SSH_PASSWORD=$SSH_PASSWORD"

    local build_output
    build_output=$(docker build \
        --no-cache \
        --progress=plain \
        $build_args \
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

# Test SSH
test_ssh() {
    local container_id="$1"
    local max_attempts=10

    log DEBUG "Testing SSH connection..."

    for ((i=1; i<=max_attempts; i++)); do
        # Check if container is still running
        if ! docker ps -q --filter "id=$container_id" | grep -q .; then
            log ERROR "Container $container_id is not running"
            return 1
        fi

        # Try to check SSH port
        local result
        result=$(docker exec "$container_id" sh -c "nc -z localhost 22 2>/dev/null && echo 'OPEN' || echo 'CLOSED'" 2>/dev/null)

        if echo "$result" | grep -q "OPEN"; then
            log DEBUG "SSH port is open"
            return 0
        fi

        log DEBUG "SSH not ready (attempt $i/$max_attempts), waiting..."
        sleep 3
    done

    # Last attempt - try docker exec
    if docker exec "$container_id" pgrep sshd >/dev/null 2>&1 || \
       docker exec "$container_id" pgrep dropbear >/dev/null 2>&1; then
        log DEBUG "SSH process found running"
        return 0
    fi

    log ERROR "SSH test failed after $max_attempts attempts"
    return 1
}

# Process single build
process_build() {
    local os="$1"
    local version="$2"

    local safe_version=$(echo "$version" | tr '/' '-' | tr ':' '-')
    local image_tag="ssh-$os:$safe_version"
    local dockerfile_path="$DOCKERFILE_DIR/${os}_${safe_version}.dockerfile"
    local log_file="$LOG_DIR/${os}_${safe_version}.log"

    log INFO "=============================================="
    log INFO "Processing: $os:$version"
    log INFO "Image: $image_tag"
    log INFO "=============================================="

    # Check disk space
    check_disk_space

    # Check if image already exists
    if docker image inspect "$image_tag" >/dev/null 2>&1; then
        log INFO "Image $image_tag already exists, removing for rebuild..."
        docker rmi "$image_tag" 2>/dev/null || true
    fi

    # Build with retry
    local build_success=false
    for ((attempt=1; attempt<=MAX_RETRIES; attempt++)); do
        log INFO "Build attempt $attempt/$MAX_RETRIES"

        if build_image "$os" "$version" "$dockerfile_path" "$image_tag" "$log_file"; then
            build_success=true
            break
        fi

        local exit_code=$?

        # Check if error is retryable
        if [[ $exit_code -eq 3 ]] || [[ $exit_code -eq 0 && "$build_success" == "true" ]]; then
            break
        fi

        if [[ $attempt -lt $MAX_RETRIES ]]; then
            log WARN "Build failed, retrying in $RETRY_DELAY seconds..."
            sleep $RETRY_DELAY
        fi
    done

    if [[ "$build_success" != "true" ]]; then
        log ERROR "Build failed for $os:$version"
        BAD_TEMPLATES["$os:$version"]="Build failed - see $log_file"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
        record_error "Build failed permanently" "$dockerfile_path"
        return 1
    fi

    # Test SSH
    log INFO "Starting container for SSH test..."
    local container_id
    container_id=$(docker run -d --rm -p 2222:22 "$image_tag" 2>&1)

    if [[ $? -ne 0 ]] || echo "$container_id" | grep -qiE "error|failed"; then
        log ERROR "Failed to start container: $container_id"
        BAD_TEMPLATES["$os:$version"]="Container start failed"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
        record_error "Container start failed" "$dockerfile_path"
        return 1
    fi

    # Wait for container to initialize
    sleep 5

    # Test SSH
    if test_ssh "$container_id"; then
        GOOD_TEMPLATES["$os:$version"]="$image_tag"
        TOTAL_SUCCESS=$((TOTAL_SUCCESS + 1))
        log SUCCESS "Successfully built and tested $os:$version"
    else
        BAD_TEMPLATES["$os:$version"]="SSH test failed"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
        record_error "SSH test failed" "$dockerfile_path"
    fi

    # Cleanup
    docker stop "$container_id" 2>/dev/null || true

    # Save progress
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

    return 0
}

# Generate Dockerfiles
generate_dockerfiles() {
    local os="$1"
    shift
    local versions=("$@")

    for version in "${versions[@]}"; do
        local safe_version=$(echo "$version" | tr '/' '-' | tr ':' '-')
        local dockerfile_path="$DOCKERFILE_DIR/${os}_${safe_version}.dockerfile"

        generate_dockerfile "$os" "$version" "$dockerfile_path"
    done
}

# Dockerfile generators
generate_dockerfile() {
    local os="$1"
    local version="$2"
    local output_file="$3"

    case "$os" in
        alpine)
            generate_alpine_dockerfile "$version" "$output_file"
            ;;
        ubuntu)
            generate_ubuntu_dockerfile "$version" "$output_file"
            ;;
        debian)
            generate_debian_dockerfile "$version" "$output_file"
            ;;
        fedora)
            generate_fedora_dockerfile "$version" "$output_file"
            ;;
        amazonlinux)
            generate_amazonlinux_dockerfile "$version" "$output_file"
            ;;
        oraclelinux)
            generate_oraclelinux_dockerfile "$version" "$output_file"
            ;;
        photon)
            generate_photon_dockerfile "$version" "$output_file"
            ;;
        busybox)
            generate_busybox_dockerfile "$version" "$output_file"
            ;;
        cirros)
            generate_cirros_dockerfile "$version" "$output_file"
            ;;
        mageia)
            generate_mageia_dockerfile "$version" "$output_file"
            ;;
        archlinux)
            generate_archlinux_dockerfile "$version" "$output_file"
            ;;
        sl|opensuse)
            generate_sles_dockerfile "$version" "$output_file"
            ;;
        almalinux|rockylinux)
            generate_rhel_dockerfile "$os" "$version" "$output_file"
            ;;
    esac
}

generate_alpine_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM alpine:${IMAGE_TAG}

ARG SSH_PASSWORD

ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN echo 'hosts: files dns' > /etc/nsswitch.conf && \
    echo "http://dl-cdn.alpinelinux.org/alpine/v${ALPINE_VERSION:-3.19}/main" > /etc/apk/repositories 2>/dev/null || true && \
    echo "http://dl-cdn.alpinelinux.org/alpine/v${ALPINE_VERSION:-3.19}/community" >> /etc/apk/repositories 2>/dev/null || true && \
    apk update

# Install OpenSSH
RUN apk add --no-cache openssh openssh-server openssh-client && \
    ssh-keygen -A && \
    mkdir -p /run/sshd /var/run

# Configure SSH
RUN sed -i 's/#PermitRootLogin.*/PermitRootLogin yes/' /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config && \
    echo "ChallengeResponseAuthentication no" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
}

generate_ubuntu_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM ubuntu:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV DEBIAN_FRONTEND=noninteractive
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true
RUN chattr -i /etc/resolv.conf 2>/dev/null || true && \
    apt-get update

# Install OpenSSH
RUN apt-get install -y --no-install-recommends \
        openssh-server \
        net-tools \
        dnsutils && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
}

generate_debian_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM debian:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV DEBIAN_FRONTEND=noninteractive
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true
RUN chattr -i /etc/resolv.conf 2>/dev/null || true && \
    apt-get update

# Install OpenSSH
RUN apt-get install -y --no-install-recommends \
        openssh-server \
        net-tools && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
}

generate_fedora_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM fedora:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
}

generate_amazonlinux_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM amazonlinux:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN yum -y update && \
    yum -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
}

generate_oraclelinux_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM oraclelinux:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
}

generate_photon_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM photon:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN tdnf -y update && \
    tdnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
}

generate_busybox_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
FROM busybox:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# Install dropbear
RUN apk add --no-cache dropbear; \
    apk add --no-cache dropbear-dbclient dropbear-scp 2>/dev/null || true

# Configure
RUN mkdir -p /etc/dropbear && \
    /usr/bin/dropbearkey -t rsa -f /etc/dropbear/dropbear_rsa_host_key

# Set password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/dropbear", "-F", "-w"]
DOCKERFILE
}

generate_cirros_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
FROM cirros:${VERSION}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# Configure SSH
RUN echo "root:${SSH_PASSWORD}" | chpasswd && \
    (sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config 2>/dev/null || true)

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
}

generate_mageia_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM mageia:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
}

generate_archlinux_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM archlinux:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN pacman -Sy --noconfirm && \
    pacman -S --noconfirm openssh && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
}

generate_sles_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM opensuse/leap:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN zypper -n update && \
    zypper -n install openssh && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
}

generate_rhel_dockerfile() {
    local base_image="$1"
    local version="$2"
    local output_file="$3"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM ${BASE_IMAGE}:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\nnameserver 8.8.4.4\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE

    sed -i "s/\${BASE_IMAGE}/$base_image/g" "$output_file"
}

# Parallel processor
process_parallel() {
    local -n queue=$1
    local pids=()
    local results=()

    while [[ ${#queue[@]} -gt 0 ]] || [[ ${#pids[@]} -gt 0 ]]; do
        # Start new jobs
        while [[ ${#pids[@]} -lt $PARALLELISM ]] && [[ ${#queue[@]} -gt 0 ]]; do
            local item="${queue[0]}"
            queue=("${queue[@]:1}")

            local os=$(echo "$item" | cut -d: -f1)
            local version=$(echo "$item" | cut -d: -f2-)

            (
                process_build "$os" "$version"
            ) &

            pids+=($!)

            # Small delay to avoid overwhelming the system
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

        # Update display
        update_display

        sleep 2
    done
}

# Update display
update_display() {
    # This would be called in the main display window
    return
}

# Main
main() {
    init

    echo ""
    echo "=============================================="
    echo -e "${CYAN}DOCKER SSH IMAGE BUILDER${NC}"
    echo "=============================================="
    echo "Parallelism: $PARALLELISM"
    echo "SSH Password: $SSH_PASSWORD"
    echo "Log Directory: $LOG_DIR"
    echo "Dockerfile Directory: $DOCKERFILE_DIR"
    echo "=============================================="
    echo ""

    # Build queue
    declare -a build_queue=()

    # Get tags for each OS
    for os in "${ALL_OS[@]}"; do
        log INFO "Fetching tags for $os..."

        local tags
        tags=$(get_tags "$os")

        if [[ -z "$tags" ]]; then
            log WARN "No tags found for $os, skipping..."
            continue
        fi

        local count=0
        while IFS= read -r version && [[ $count -lt 10 ]]; do
            if [[ -n "$version" ]]; then
                build_queue+=("$os:$version")
                count=$((count + 1))
            fi
        done <<< "$tags"

        log INFO "Added $count versions of $os to build queue"
    done

    local total_items=${#build_queue[@]}
    log INFO "Total items to build: $total_items"

    # Process queue
    process_parallel build_queue

    # Final summary
    display_final_summary
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
        print_error_summary
    fi

    echo ""
    echo "=============================================="
    echo "Results saved to:"
    echo "  - $OUTPUT_DIR/good_templates.txt"
    echo "  - $OUTPUT_DIR/bad_templates.txt"
    echo "=============================================="
}

# Export functions
export -f log record_error print_error_summary check_disk_space retry wait_for_rate_limit
export -f parse_build_error get_tags build_image test_ssh process_build
export -f generate_dockerfile generate_alpine_dockerfile generate_ubuntu_dockerfile
export -f generate_debian_dockerfile generate_fedora_dockerfile generate_amazonlinux_dockerfile
export -f generate_oraclelinux_dockerfile generate_photon_dockerfile generate_busybox_dockerfile
export -f generate_cirros_dockerfile generate_mageia_dockerfile generate_archlinux_dockerfile
export -f generate_sles_dockerfile generate_rhel_dockerfile

main "$@"
