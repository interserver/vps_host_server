#!/bin/bash
#
# Generate Dockerfiles only without building
# Useful for pre-generating templates or testing Dockerfile syntax
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DOCKERFILE_DIR="$SCRIPT_DIR/dockerfiles"

# OS List
declare -a ALL_OS=(
    "busybox" "ubuntu" "fedora" "debian" "cirros" "mageia"
    "oraclelinux" "alpine" "photon" "amazonlinux" "almalinux"
    "rockylinux" "sl" "archlinux"
)

# Common versions for testing
declare -A TEST_VERSIONS=(
    ["alpine"]="3.19 3.20 edge"
    ["ubuntu"]="24.04 22.04 20.04"
    ["debian"]="bookworm bullseye buster"
    ["fedora"]="40 39 38"
    ["amazonlinux"]="2023"
    ["oraclelinux"]="9 8"
    ["photon"]="5.0 4.0 3.0"
    ["busybox"]="1.36 1.35"
    ["cirros"]="0.5"
    ["mageia"]="7"
    ["archlinux"]="latest"
    ["sl"]="15"
    ["almalinux"]="9 8"
    ["rockylinux"]="9 8"
)

SSH_PASSWORD="InterServer!23"

mkdir -p "$DOCKERFILE_DIR"

echo "Generating Dockerfiles in $DOCKERFILE_DIR..."
echo ""

for os in "${ALL_OS[@]}"; do
    echo "Processing $os..."

    # Get versions
    if [[ -n "${TEST_VERSIONS[$os]}" ]]; then
        versions="${TEST_VERSIONS[$os]}"
    else
        # Default versions
        versions="latest"
    fi

    for version in $versions; do
        safe_version=$(echo "$version" | tr '/' '-' | tr ':' '-')
        output_file="$DOCKERFILE_DIR/${os}_${safe_version}.dockerfile"

        echo "  - $version -> ${output_file##*/}"

        # Generate based on OS type
        case "$os" in
            alpine)
                generate_alpine "$version" "$output_file"
                ;;
            ubuntu)
                generate_ubuntu "$version" "$output_file"
                ;;
            debian)
                generate_debian "$version" "$output_file"
                ;;
            fedora)
                generate_fedora "$version" "$output_file"
                ;;
            amazonlinux)
                generate_amazonlinux "$version" "$output_file"
                ;;
            oraclelinux)
                generate_oraclelinux "$version" "$output_file"
                ;;
            photon)
                generate_photon "$version" "$output_file"
                ;;
            busybox)
                generate_busybox "$version" "$output_file"
                ;;
            cirros)
                generate_cirros "$version" "$output_file"
                ;;
            mageia)
                generate_mageia "$version" "$output_file"
                ;;
            archlinux)
                generate_archlinux "$version" "$output_file"
                ;;
            sl|opensuse)
                generate_sles "$version" "$output_file"
                ;;
            almalinux|rockylinux)
                generate_rhel "$os" "$version" "$output_file"
                ;;
        esac
    done
done

echo ""
echo "Generated $(find "$DOCKERFILE_DIR" -name "*.dockerfile" | wc -l) Dockerfiles"
echo ""

# List generated files
echo "Generated files:"
find "$DOCKERFILE_DIR" -name "*.dockerfile" | sort | while read -r f; do
    echo "  - ${f##*/}"
done

# Dockerfile templates
generate_alpine() {
    local version="$1"
    local output="$2"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM alpine:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix
RUN echo 'hosts: files dns' > /etc/nsswitch.conf && \\
    apk update

# Install OpenSSH
RUN apk add --no-cache openssh openssh-server openssh-client && \\
    ssh-keygen -A && \\
    mkdir -p /run/sshd /var/run

# Configure SSH
RUN sed -i 's/#PermitRootLogin.*/PermitRootLogin yes/' /etc/ssh/sshd_config && \\
    echo "PasswordAuthentication yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

generate_ubuntu() {
    local version="$1"
    local output="$2"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM ubuntu:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV DEBIAN_FRONTEND=noninteractive
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\\nnameserver 8.8.4.4\\n' > /etc/resolv.conf) 2>/dev/null || true

RUN apt-get update

# Install OpenSSH
RUN apt-get install -y --no-install-recommends \\
        openssh-server \\
        net-tools && \\
    mkdir -p /run/sshd && \\
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \\
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

generate_debian() {
    local version="$1"
    local output="$2"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM debian:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV DEBIAN_FRONTEND=noninteractive
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\\nnameserver 8.8.4.4\\n' > /etc/resolv.conf) 2>/dev/null || true

RUN apt-get update

# Install OpenSSH
RUN apt-get install -y --no-install-recommends \\
        openssh-server && \\
    mkdir -p /run/sshd && \\
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \\
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

generate_fedora() {
    local version="$1"
    local output="$2"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM fedora:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\\nnameserver 8.8.4.4\\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y update && \\
    dnf -y install openssh-server && \\
    mkdir -p /run/sshd && \\
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \\
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

generate_amazonlinux() {
    local version="$1"
    local output="$2"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM amazonlinux:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\\nnameserver 8.8.4.4\\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN yum -y update && \\
    yum -y install openssh-server && \\
    mkdir -p /run/sshd && \\
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \\
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

generate_oraclelinux() {
    local version="$1"
    local output="$2"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM oraclelinux:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\\nnameserver 8.8.4.4\\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y update && \\
    dnf -y install openssh-server && \\
    mkdir -p /run/sshd && \\
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \\
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

generate_photon() {
    local version="$1"
    local output="$2"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM photon:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\\nnameserver 8.8.4.4\\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN tdnf -y update && \\
    tdnf -y install openssh-server && \\
    mkdir -p /run/sshd && \\
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \\
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

generate_busybox() {
    local version="$1"
    local output="$2"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
FROM busybox:${version}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# Install dropbear
RUN apk add --no-cache dropbear; \\
    apk add --no-cache dropbear-dbclient dropbear-scp 2>/dev/null || true

# Configure
RUN mkdir -p /etc/dropbear && \\
    /usr/bin/dropbearkey -t rsa -f /etc/dropbear/dropbear_rsa_host_key

# Set password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/dropbear", "-F", "-w"]
EOF
}

generate_cirros() {
    local version="$1"
    local output="$2"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
FROM cirros:${version}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# Configure SSH
RUN echo "root:\${SSH_PASSWORD}" | chpasswd && \\
    (sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config 2>/dev/null || true)

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

generate_mageia() {
    local version="$1"
    local output="$2"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM mageia:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\\nnameserver 8.8.4.4\\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y install openssh-server && \\
    mkdir -p /run/sshd && \\
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \\
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

generate_archlinux() {
    local version="$1"
    local output="$2"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM archlinux:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\\nnameserver 8.8.4.4\\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN pacman -Sy --noconfirm && \\
    pacman -S --noconfirm openssh && \\
    mkdir -p /run/sshd && \\
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \\
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

generate_sles() {
    local version="$1"
    local output="$2"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM opensuse/leap:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\\nnameserver 8.8.4.4\\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN zypper -n update && \\
    zypper -n install openssh && \\
    mkdir -p /run/sshd && \\
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \\
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}

generate_rhel() {
    local base_image="$1"
    local version="$2"
    local output="$3"

    cat > "$output" << EOF
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
ARG IMAGE_TAG=${version}
FROM ${base_image}:${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=\${SSH_PASSWORD}

# DNS fix (tolerate read-only mount in BuildKit)
RUN (rm -f /etc/resolv.conf 2>/dev/null; printf 'nameserver 8.8.8.8\\nnameserver 8.8.4.4\\n' > /etc/resolv.conf) 2>/dev/null || true

# Install OpenSSH
RUN dnf -y update && \\
    dnf -y install openssh-server && \\
    mkdir -p /run/sshd && \\
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config && \\
    echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:\${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
EOF
}
