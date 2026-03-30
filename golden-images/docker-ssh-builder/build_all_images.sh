#!/bin/bash
#
# Docker SSH Image Builder and Tester
# Builds Docker images with SSH enabled for multiple OS distributions
# Author: MiniMax Agent
#

set -o pipefail

# Configuration
PARALLELISM="${PARALLELISM:-6}"
SSH_PASSWORD="InterServer!23"
LOG_DIR="/workspace/docker-ssh-builder/logs"
DOCKERFILE_DIR="/workspace/docker-ssh-builder/dockerfiles"
OUTPUT_DIR="/workspace/docker-ssh-builder/output"
SPACE_THRESHOLD_MB=5000
RATE_LIMIT_BACKOFF_SECONDS=60

# Color codes for terminal output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Lists
declare -a ALL_OS=(
    "busybox" "ubuntu" "fedora" "debian" "cirros" "mageia"
    "oraclelinux" "alpine" "photon" "amazonlinux" "almalinux"
    "rockylinux" "sl" "archlinux"
)

# Tracking variables
declare -A GOOD_TEMPLATES=()
declare -A BAD_TEMPLATES=()
declare -A ERROR_GROUPS=()
TOTAL_SUCCESS=0
TOTAL_FAILED=0
START_TIME=$(date +%s)

# Create directories
mkdir -p "$LOG_DIR" "$DOCKERFILE_DIR" "$OUTPUT_DIR"

# Logging functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
    echo "[INFO] $(date '+%Y-%m-%d %H:%M:%S') $1" >> "$LOG_DIR/build.log"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
    echo "[SUCCESS] $(date '+%Y-%m-%d %H:%M:%S') $1" >> "$LOG_DIR/build.log"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
    echo "[ERROR] $(date '+%Y-%m-%d %H:%M:%S') $1" >> "$LOG_DIR/build.log"
}

log_warn() {
    echo -e "${YELLOW}[WARN]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
    echo "[WARN] $(date '+%Y-%m-%d %H:%M:%S') $1" >> "$LOG_DIR/build.log"
}

log_debug() {
    echo -e "${MAGENTA}[DEBUG]${NC} $(date '+%Y-%m-%d %H:%M:%S') $1"
    echo "[DEBUG] $(date '+%Y-%m-%d %H:%M:%S') $1" >> "$LOG_DIR/build.log"
}

# Record error for grouping
record_error() {
    local error_msg="$1"
    local file="$2"
    local key=$(echo "$error_msg" | md5sum | cut -d' ' -f1)

    if [[ -z "${ERROR_GROUPS[$key]}" ]]; then
        ERROR_GROUPS[$key]="$error_msg|$file"
    else
        ERROR_GROUPS[$key]="${ERROR_GROUPS[$key]},$file"
    fi
}

# Print error summary
print_error_summary() {
    echo ""
    echo "=============================================="
    echo -e "${RED}ERROR SUMMARY (Grouped by Error Type)${NC}"
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

    echo ""
    echo "=============================================="
}

# Check and free disk space
check_disk_space() {
    local available=$(df -m / | tail -1 | awk '{print $4}')
    log_info "Available disk space: ${available}MB"

    if [[ $available -lt $SPACE_THRESHOLD_MB ]]; then
        log_warn "Low disk space (${available}MB), attempting cleanup..."

        # Clean Docker system
        docker system prune -af --volumes 2>/dev/null || true

        # Clean apt cache
        apt-get clean 2>/dev/null || true
        rm -rf /var/cache/apt/archives/* 2>/dev/null || true

        # Check again
        available=$(df -m / | tail -1 | awk '{print $4}')
        log_info "After cleanup: ${available}MB available"
    fi
}

# Wait for Docker rate limit
wait_for_rate_limit() {
    log_warn "Rate limit detected, waiting ${RATE_LIMIT_BACKOFF_SECONDS} seconds..."
    sleep $RATE_LIMIT_BACKOFF_SECONDS
}

# Parse error from build output
parse_build_error() {
    local output="$1"
    local file="$2"

    # Rate limiting
    if echo "$output" | grep -qi "429\|too many requests\|rate.limit\|exceeded.*limit"; then
        wait_for_rate_limit
        return 1
    fi

    # Connection errors
    if echo "$output" | grep -qi "connection reset\|ECONNRESET"; then
        record_error "Connection reset error" "$file"
        return 2
    fi

    if echo "$output" | grep -qi "tls handshake\|TLS\|handshake timeout"; then
        record_error "TLS handshake timeout" "$file"
        return 2
    fi

    if echo "$output" | grep -qi "dial tcp\|dial timeout\|connection timeout"; then
        record_error "Dial TCP timeout error" "$file"
        return 2
    fi

    if echo "$output" | grep -qi "i/o timeout\|io timeout"; then
        record_error "I/O timeout error" "$file"
        return 2
    fi

    if echo "$output" | grep -qi "deadline exceeded\|context deadline"; then
        record_error "Deadline exceeded error" "$file"
        return 2
    fi

    # No space left
    if echo "$output" | grep -qi "no space\|ENOSPC\|disk quota"; then
        check_disk_space
        record_error "Disk space error" "$file"
        return 2
    fi

    # Network/DNS issues
    if echo "$output" | grep -qi "network\|dns\|resolve\|could not resolve"; then
        record_error "Network/DNS resolution error" "$file"
        return 2
    fi

    return 0
}

# Get Docker Hub tags with error handling and v1 manifest filtering
get_tags() {
    local image="$1"
    local max_tags=10

    local result=$(curl -s "https://hub.docker.com/v2/repositories/library/$image/tags?page_size=$max_tags&page=1" 2>&1)

    # Check for v1 manifest
    if echo "$result" | grep -qi "manifest unknown\|manifest v2\|v1.*not supported"; then
        log_warn "Skipping $image - v1 manifest only"
        echo ""
        return 1
    fi

    # Check for rate limiting
    if echo "$result" | grep -qi "429\|too many requests"; then
        wait_for_rate_limit
        result=$(curl -s "https://hub.docker.com/v2/repositories/library/$image/tags?page_size=$max_tags&page=1")
    fi

    # Check for errors
    if echo "$result" | grep -qi "error\|not found\|unauthorized"; then
        log_error "Failed to fetch tags for $image"
        echo ""
        return 1
    fi

    # Extract tag names
    echo "$result" | grep -o '"name":"[^"]*"' | head -n $max_tags | sed 's/"name":"//;s/"//g' | grep -v "^$"
}

# Generate Dockerfile for Alpine
generate_alpine_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM alpine:${IMAGE_TAG}

# HADOLINT SKIP SECRETS
ENV PASSWD="${SSH_PASSWORD}"

# Fix DNS resolution
RUN echo 'hosts: files dns' > /etc/nsswitch.conf && \
    echo "http://dl-cdn.alpinelinux.org/alpine/latest-stable/main" > /etc/apk/repositories && \
    echo "http://dl-cdn.alpinelinux.org/alpine/latest-stable/community" >> /etc/apk/repositories && \
    apk update

# Install OpenSSH
RUN apk add --no-cache openssh openssh-server openssh-client && \
    ssh-keygen -A && \
    mkdir -p /run/sshd

# Configure SSH
RUN echo "PermitRootLogin yes" >> /etc/ssh/sshd_config && \
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config && \
    echo "ChallengeResponseAuthentication no" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g" "$output_file"
}

# Generate Dockerfile for Ubuntu
generate_ubuntu_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM ubuntu:${IMAGE_TAG}

# HADOLINT SKIP SECRETS
ENV DEBIAN_FRONTEND=noninteractive
ENV SSH_PASSWORD="${SSH_PASSWORD}"

# Fix DNS and update
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        dnsutils \
        net-tools \
        ca-certificates && \
    echo 'options timeout:2 attempts:3 rotate' > /etc/resolv.conf.head && \
    rm -f /etc/resolv.conf && \
    touch /etc/resolv.conf && \
    chattr -i /etc/resolv.conf 2>/dev/null || true && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf && \
    apt-get update || (sleep 5 && apt-get update) || (sleep 10 && apt-get update)

# Install OpenSSH
RUN apt-get install -y openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin yes/' /etc/ssh/sshd_config || true && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g" "$output_file"
}

# Generate Dockerfile for Debian
generate_debian_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM debian:${IMAGE_TAG}

# HADOLINT SKIP SECRETS
ENV DEBIAN_FRONTEND=noninteractive
ENV SSH_PASSWORD="${SSH_PASSWORD}"

# Fix DNS and update
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        dnsutils \
        net-tools \
        ca-certificates && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf && \
    apt-get update || (sleep 5 && apt-get update) || (sleep 10 && apt-get update)

# Install OpenSSH
RUN apt-get install -y openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g" "$output_file"
}

# Generate Dockerfile for Fedora
generate_fedora_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM fedora:${IMAGE_TAG}

# HADOLINT SKIP SECRETS
ENV SSH_PASSWORD="${SSH_PASSWORD}"

# Fix DNS
RUN echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g" "$output_file"
}

# Generate Dockerfile for CentOS/Rocky/Alma
generate_rhel_dockerfile() {
    local base_image="$1"
    local version="$2"
    local output_file="$3"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM ${BASE_IMAGE}:${IMAGE_TAG}

# HADOLINT SKIP SECRETS
ENV SSH_PASSWORD="${SSH_PASSWORD}"

# Fix DNS
RUN echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g; s/\${BASE_IMAGE}/$base_image/g" "$output_file"
}

# Generate Dockerfile for Amazon Linux
generate_amazonlinux_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM amazonlinux:${IMAGE_TAG}

# HADOLINT SKIP SECRETS
ENV SSH_PASSWORD="${SSH_PASSWORD}"

# Fix DNS
RUN echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN yum -y update && \
    yum -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g" "$output_file"
}

# Generate Dockerfile for Oracle Linux
generate_oraclelinux_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM oraclelinux:${IMAGE_TAG}

# HADOLINT SKIP SECRETS
ENV SSH_PASSWORD="${SSH_PASSWORD}"

# Fix DNS
RUN echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN dnf -y update && \
    dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g" "$output_file"
}

# Generate Dockerfile for Photon OS
generate_photon_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM photon:${IMAGE_TAG}

# HADOLINT SKIP SECRETS
ENV SSH_PASSWORD="${SSH_PASSWORD}"

# Fix DNS
RUN echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN tdnf -y update && \
    tdnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g" "$output_file"
}

# Generate Dockerfile for BusyBox
generate_busybox_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
FROM busybox:${VERSION}

# HADOLINT SKIP SECRETS
ENV SSH_PASSWORD="${SSH_PASSWORD}"

# BusyBox minimal SSH (dropbear)
RUN apk add --no-cache dropbear

# Configure dropbear
RUN mkdir -p /etc/dropbear && \
    /usr/bin/dropbearkey -t rsa -f /etc/dropbear/dropbear_rsa_host_key && \
    echo "root:${SSH_PASSWORD}" | chpasswd

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/dropbear", "-F", "-w"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g" "$output_file"
}

# Generate Dockerfile for Cirros
generate_cirros_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
FROM cirros:${VERSION}

# HADOLINT SKIP SECRETS
ENV SSH_PASSWORD="${SSH_PASSWORD}"

# Cirros already has SSH, configure it
RUN echo "root:${SSH_PASSWORD}" | chpasswd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config || true

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g" "$output_file"
}

# Generate Dockerfile for Mageia
generate_mageia_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM mageia:${IMAGE_TAG}

# HADOLINT SKIP SECRETS
ENV SSH_PASSWORD="${SSH_PASSWORD}"

# Fix DNS
RUN echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN dnf -y install openssh-server && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g" "$output_file"
}

# Generate Dockerfile for Arch Linux
generate_archlinux_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM archlinux:${IMAGE_TAG}

# HADOLINT SKIP SECRETS
ENV SSH_PASSWORD="${SSH_PASSWORD}"

# Fix DNS and update
RUN echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf && \
    pacman -Sy --noconfirm && \
    pacman -S --noconfirm openssh

# Install and configure OpenSSH
RUN mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g" "$output_file"
}

# Generate Dockerfile for openSUSE/SL
generate_sles_dockerfile() {
    local version="$1"
    local output_file="$2"

    cat > "$output_file" << 'DOCKERFILE'
# syntax=docker/dockerfile:1
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${VERSION}
FROM opensuse/leap:${IMAGE_TAG}

# HADOLINT SKIP SECRETS
ENV SSH_PASSWORD="${SSH_PASSWORD}"

# Fix DNS
RUN echo "nameserver 8.8.8.8" > /etc/resolv.conf && \
    echo "nameserver 8.8.4.4" >> /etc/resolv.conf

# Install OpenSSH
RUN zypper -n update && \
    zypper -n install openssh && \
    mkdir -p /run/sshd && \
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

# Expose SSH port
EXPOSE 22

# Start SSH
CMD ["/usr/sbin/sshd", "-D"]
DOCKERFILE
    sed -i "s/\${VERSION}/$version/g" "$output_file"
}

# Dispatch to correct generator based on OS
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
        *)
            log_error "Unknown OS: $os"
            return 1
            ;;
    esac
}

# Build Docker image
build_image() {
    local os="$1"
    local version="$2"
    local dockerfile_path="$3"
    local image_tag="$4"
    local log_file="$5"

    local build_args="--build-arg VERSION=$version --build-arg SSH_PASSWORD=$SSH_PASSWORD --build-arg PASSWD=$SSH_PASSWORD"

    # Add DOCKER_BUILDKIT=1 for better error handling
    export DOCKER_BUILDKIT=1

    # Try to build, capture output
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

    # Parse for errors
    if [[ $exit_code -ne 0 ]]; then
        parse_build_error "$build_output" "$dockerfile_path"
    fi

    return $exit_code
}

# Test SSH connection
test_ssh() {
    local container_id="$1"
    local timeout_seconds=30

    log_info "Testing SSH connection on container $container_id..."

    # Wait for SSH to be ready
    local retries=0
    local max_retries=10

    while [[ $retries -lt $max_retries ]]; do
        # Try to connect
        local result=$(docker exec "$container_id" sh -c "nc -z localhost 22 && echo 'SSH port open'" 2>/dev/null || echo "failed")

        if echo "$result" | grep -q "SSH port open"; then
            log_success "SSH is running and port 22 is accessible"
            return 0
        fi

        retries=$((retries + 1))
        sleep 3
    done

    log_error "SSH test failed after $max_retries retries"
    return 1
}

# Build and test single image
build_and_test() {
    local os="$1"
    local version="$2"

    local image_name="ssh-$os:$version"
    local safe_version=$(echo "$version" | tr '/' '-')
    local dockerfile_path="$DOCKERFILE_DIR/${os}_${safe_version}.dockerfile"
    local log_file="$LOG_DIR/${os}_${safe_version}.log"

    log_info "Processing: $os:$version"

    # Generate Dockerfile
    if ! generate_dockerfile "$os" "$version" "$dockerfile_path"; then
        BAD_TEMPLATES["$os:$version"]="Dockerfile generation failed"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
        return 1
    fi

    # Check disk space
    check_disk_space

    # Build image
    log_info "Building $image_name..."
    if ! build_image "$os" "$version" "$dockerfile_path" "$image_name" "$log_file"; then
        BAD_TEMPLATES["$os:$version"]="Build failed - see $log_file"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
        record_error "Build failed" "$dockerfile_path"
        return 1
    fi

    # Run container
    log_info "Starting container for testing..."
    local container_id
    container_id=$(docker run -d --rm -p 2222:22 "$image_name" 2>&1)

    if [[ $? -ne 0 ]] || echo "$container_id" | grep -q "error"; then
        log_error "Failed to start container: $container_id"
        BAD_TEMPLATES["$os:$version"]="Container start failed"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
        return 1
    fi

    # Wait for container to initialize
    sleep 5

    # Test SSH
    if test_ssh "$container_id"; then
        GOOD_TEMPLATES["$os:$version"]="$image_name"
        TOTAL_SUCCESS=$((TOTAL_SUCCESS + 1))
        log_success "Successfully built and tested $os:$version"
    else
        BAD_TEMPLATES["$os:$version"]="SSH test failed"
        TOTAL_FAILED=$((TOTAL_FAILED + 1))
    fi

    # Cleanup container
    docker stop "$container_id" 2>/dev/null || true

    return 0
}

# Process queue (for parallel execution)
process_queue() {
    local -n queue=$1
    local pids=()

    while [[ ${#queue[@]} -gt 0 ]] || [[ ${#pids[@]} -gt 0 ]]; do
        # Start new jobs up to parallelism limit
        while [[ ${#pids[@]} -lt $PARALLELISM ]] && [[ ${#queue[@]} -gt 0 ]]; do
            local item="${queue[0]}"
            queue=("${queue[@]:1}")

            local os=$(echo "$item" | cut -d: -f1)
            local version=$(echo "$item" | cut -d: -f2-)

            (
                build_and_test "$os" "$version"
            ) &

            pids+=($!)
        done

        # Wait for some jobs to complete
        sleep 2

        # Clean up finished jobs
        local remaining_pids=()
        for pid in "${pids[@]}"; do
            if kill -0 $pid 2>/dev/null; then
                remaining_pids+=($pid)
            fi
        done
        pids=("${remaining_pids[@]}")

        # Update display
        update_summary_display
    done
}

# Update summary display
update_summary_display() {
    clear
    echo ""
    echo "=============================================="
    echo -e "${CYAN}DOCKER SSH IMAGE BUILDER - SUMMARY${NC}"
    echo "=============================================="
    echo ""
    echo "Started: $(date -d @${START_TIME} '+%Y-%m-%d %H:%M:%S')"
    echo "Runtime: $(($(date +%s) - START_TIME)) seconds"
    echo ""
    echo -e "${GREEN}Success: $TOTAL_SUCCESS${NC}"
    echo -e "${RED}Failed:  $TOTAL_FAILED${NC}"
    echo ""

    if [[ ${#GOOD_TEMPLATES[@]} -gt 0 ]]; then
        echo "Good Templates:"
        for key in "${!GOOD_TEMPLATES[@]}"; do
            echo "  - $key -> ${GOOD_TEMPLATES[$key]}"
        done
    fi

    if [[ ${#BAD_TEMPLATES[@]} -gt 0 ]]; then
        echo ""
        echo "Bad Templates:"
        for key in "${!BAD_TEMPLATES[@]}"; do
            echo "  - $key: ${BAD_TEMPLATES[$key]}"
        done
    fi

    echo ""
    echo "Processing: ${#queue[@]} remaining in queue..."
    echo "=============================================="
}

# Export functions needed for parallel execution
export -f build_and_test build_image test_ssh generate_dockerfile
export -f parse_build_error record_error check_disk_space wait_for_rate_limit
export -f log_info log_success log_error log_warn log_debug
export SSH_PASSWORD LOG_DIR DOCKERFILE_DIR PARALLELISM
export -f generate_alpine_dockerfile generate_ubuntu_dockerfile generate_debian_dockerfile
export -f generate_fedora_dockerfile generate_rhel_dockerfile generate_amazonlinux_dockerfile
export -f generate_oraclelinux_dockerfile generate_photon_dockerfile generate_busybox_dockerfile
export -f generate_cirros_dockerfile generate_mageia_dockerfile generate_archlinux_dockerfile
export -f generate_sles_dockerfile
export GOOD_TEMPLATES BAD_TEMPLATES TOTAL_SUCCESS TOTAL_FAILED ERROR_GROUPS

# Main execution
main() {
    echo "=============================================="
    echo "DOCKER SSH IMAGE BUILDER"
    echo "Parallelism: $PARALLELISM"
    echo "SSH Password: $SSH_PASSWORD"
    echo "Log Directory: $LOG_DIR"
    echo "Dockerfile Directory: $DOCKERFILE_DIR"
    echo "=============================================="

    # Initialize queue
    declare -a build_queue=()

    # Get tags for each OS and add to queue
    for os in "${ALL_OS[@]}"; do
        log_info "Fetching tags for $os..."

        local tags
        tags=$(get_tags "$os")

        if [[ -z "$tags" ]]; then
            log_warn "No tags found for $os, skipping..."
            continue
        fi

        local count=0
        while IFS= read -r version && [[ $count -lt 10 ]]; do
            if [[ -n "$version" ]]; then
                build_queue+=("$os:$version")
                count=$((count + 1))
            fi
        done <<< "$tags"

        log_info "Added $count versions of $os to build queue"
    done

    local total_items=${#build_queue[@]}
    log_info "Total items to build: $total_items"

    # Process queue with parallelism
    process_queue build_queue

    # Final summary
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
        echo "  ✓ $key"
    done

    echo ""
    echo "Bad Templates (${#BAD_TEMPLATES[@]}):"
    for key in "${!BAD_TEMPLATES[@]}"; do
        echo "  ✗ $key: ${BAD_TEMPLATES[$key]}"
    done

    # Print error summary if any
    if [[ ${#ERROR_GROUPS[@]} -gt 0 ]]; then
        print_error_summary
    fi

    # Save lists to files
    echo "${!GOOD_TEMPLATES[@]}" | tr ' ' '\n' > "$OUTPUT_DIR/good_templates.txt"
    echo "${!BAD_TEMPLATES[@]}" | tr ' ' '\n' > "$OUTPUT_DIR/bad_templates.txt"

    echo ""
    echo "Results saved to:"
    echo "  - $OUTPUT_DIR/good_templates.txt"
    echo "  - $OUTPUT_DIR/bad_templates.txt"
    echo ""
    echo "=============================================="
}

# Run main
main "$@"
