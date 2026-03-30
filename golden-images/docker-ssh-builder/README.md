# Docker SSH Image Builder

**Version 2.0** - Advanced Docker image builder with TUI dashboard, registry authentication, and per-distro plugins.

A comprehensive shell script system that builds Docker images with SSH enabled for multiple Linux distributions. Supports parallel builds, automatic error handling, retry logic, and real-time progress monitoring.

## Features

### v2 New Features
- **Real-time TUI Dashboard** - ASCII-based terminal UI with progress bars, build grid, and error summary
- **Docker Registry Authentication** - Token-based auth with caching to reduce rate limiting
- **Per-Distro Plugin Handlers** - Custom Dockerfile generators for each OS with auto-patching
- **Structured JSON Output** - Machine-readable results with `jq` support
- **Tags Cache** - Persistent caching of Docker Hub tags to reduce API calls
- **Auto Repository Patching** - Fixes for broken repos (vault.centos.org, old mirrors, etc.)

### Core Features
- **14 OS Distributions**: busybox, ubuntu, fedora, debian, cirros, mageia, oraclelinux, alpine, photon, amazonlinux, almalinux, rockylinux, sl, archlinux
- **10 Most Recent Versions** per OS (API-driven discovery)
- **Parallel Building** - Configurable parallelism (default: 6)
- **Error Handling** - Comprehensive error detection and grouping
- **Retry Logic** - Automatic retry with exponential backoff
- **Rate Limiting** - Docker Hub rate limit detection and backoff
- **Disk Management** - Automatic space cleanup
- **SSH Testing** - Verifies SSH is running on each image
- **Comprehensive Logging** - All build output logged

## Quick Start

```bash
cd /workspace/docker-ssh-builder

# Run with TUI dashboard (RECOMMENDED)
make build-v2

# Run with custom parallelism
make build-v2 P=8

# Generate Dockerfiles only (no build)
make dockerfiles

# View results as JSON
make json-results
```

## Configuration

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `PARALLELISM` | 6 | Number of parallel build processes |
| `SSH_PASSWORD` | InterServer!23 | SSH password for containers |

### Example

```bash
PARALLELISM=4 SSH_PASSWORD=secret make build-v2
```

## Directory Structure

```
docker-ssh-builder/
├── docker_ssh_builder_v2.sh  # Main v2 build script with TUI
├── build_images_enhanced.sh  # v1 build script
├── generate_dockerfiles.sh   # Generate Dockerfiles only
├── summary.sh               # Standalone summary display
├── Makefile                 # Make targets
├── dockerfiles/             # Generated Dockerfiles
├── logs/                    # Build logs
└── output/                  # Results and cache
    ├── results.json         # Structured JSON results
    ├── state.json           # Real-time state
    ├── tags_cache.json      # Docker Hub tags cache
    ├── good_templates.txt   # List of successful builds
    └── bad_templates.txt    # List of failed builds
```

## Supported Operating Systems

| OS | Package Manager | SSH Package |
|----|-----------------|-------------|
| Alpine | apk | openssh |
| Ubuntu | apt | openssh-server |
| Debian | apt | openssh-server |
| Fedora | dnf | openssh-server |
| Amazon Linux | yum | openssh-server |
| Oracle Linux | dnf | openssh-server |
| Photon OS | tdnf | openssh-server |
| BusyBox | none | dropbear |
| Cirros | none | openssh (pre-installed) |
| Mageia | dnf | openssh-server |
| Arch Linux | pacman | openssh |
| openSUSE/SL | zypper | openssh |
| AlmaLinux | dnf | openssh-server |
| Rocky Linux | dnf | openssh-server |

## TUI Dashboard

The v2 builder features a real-time TUI dashboard:

```
┌─────────────────────────────────────────────────────────┐
│ PARALLELISM: 6          SSH PASSWORD: ********           │
│ STARTED: 10:30:00       RUNTIME: 00:15:32               │
└─────────────────────────────────────────────────────────┘

┌ OVERALL PROGRESS ───────────────────────────────────────┐
│ [████████████░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░░]  │
│ ✓ SUCCESS: 45    (45%)   ✗ FAILED: 10    (10%)        │
│ ○ PENDING: 45              Success Rate: 82%            │
└─────────────────────────────────────────────────────────┘

┌ CURRENT BUILD ─────────────────────────────────────────┐
│ Building: ubuntu:22.04                                   │
└─────────────────────────────────────────────────────────┘

┌ BUILD STATUS GRID ──────────────────────────────────────┐
│ ✓ alpine:3.19        ✓ ubuntu:22.04       ◐ debian:12  │
│ ✗ fedora:38          ○ amazonlinux:2023  ○ oracle:9   │
└─────────────────────────────────────────────────────────┘

┌ ERROR SUMMARY ──────────────────────────────────────────┐
│ 1. DNS/Network resolution error (affects 3 files)       │
│ 2. TLS/SSL handshake timeout (affects 2 files)          │
└─────────────────────────────────────────────────────────┘
```

## JSON Output

Results are saved in structured JSON format:

```json
{
  "results": {
    "version": "2.0.0",
    "completed_at": "2024-01-15T10:45:32Z",
    "duration_seconds": 932,
    "statistics": {
      "total_builds": 100,
      "successful": 82,
      "failed": 18,
      "success_rate_percent": 82
    },
    "good_templates": [
      "alpine:3.19",
      "ubuntu:22.04",
      ...
    ],
    "bad_templates": [
      {"template": "fedora:38", "error": "Build failed"},
      ...
    ],
    "errors": [
      {"error": "DNS/Network resolution error", "files": ["file1", "file2"]}
    ]
  }
}
```

## Error Handling

The script automatically detects and handles:

### HTTP/Rate Limiting
- **429 Too Many Requests** - Automatic backoff
- **Rate Limit Exceeded** - Respects Docker Hub limits
- **Token-based Auth** - Reduces anonymous rate limiting

### Network Errors
- **Connection Reset (ECONNRESET)** - Retry with backoff
- **TLS Handshake Timeout** - Retry with extended timeout
- **Dial TCP Timeout** - Retry with backoff
- **I/O Timeout** - Retry with backoff
- **Deadline Exceeded** - Retry or skip

### Repository Errors
- **Broken/Missing Repos** - Auto-patching for vault.centos.org, old mirrors
- **DNS Resolution** - DNS fixes included in Dockerfiles
- **v1 Manifest** - Skips unsupported images

### Other
- **Disk Space** - Automatic cleanup
- **Authentication Denied** - Skips protected images

## Per-Distro Plugins

Each OS has a specialized Dockerfile generator that handles:

1. **DNS Configuration** - Resolves /etc/resolv.conf issues
2. **Package Manager Setup** - Configures repositories
3. **Repository Patching** - Fixes broken mirrors
4. **SSH Installation** - Installs correct SSH package
5. **SSH Configuration** - Enables password authentication
6. **Password Setup** - Sets root password

## Usage Examples

### Basic Usage

```bash
# Run the v2 builder with TUI
./docker_ssh_builder_v2.sh

# Or use make
make build-v2
```

### With Custom Settings

```bash
# 8 parallel builds with custom password
PARALLELISM=8 SSH_PASSWORD=mypass make build-v2

# Run v1 builder (no TUI)
make build
```

### View Results

```bash
# View JSON results
cat output/results.json | jq '.results.statistics'

# View good templates
cat output/good_templates.txt

# View bad templates with errors
cat output/bad_templates.txt

# View error summary
grep "ERROR" logs/build.log
```

### Dockerfiles Only

```bash
# Generate Dockerfiles without building
make dockerfiles

# List generated files
make list
```

### Cleanup

```bash
# Clean all generated files
make clean

# Clean only Docker images
make clean-images
```

## Dockerfile Features

Each generated Dockerfile includes:

```dockerfile
# syntax=docker/dockerfile:1
# pragma: SAFETY SKIP SECRETS
# HADOLINT SKIP SECRETS
# hadolint ignore=DL3065,DL3060,DL3059

ARG IMAGE_TAG=${VERSION}
FROM ${IMAGE_TAG}

ARG SSH_PASSWORD
ENV SSH_PASSWORD=${SSH_PASSWORD}

# DNS fix
RUN rm -f /etc/resolv.conf && \
    echo "nameserver 8.8.8.8" > /etc/resolv.conf

# Install OpenSSH (varies by distro)
RUN apk add --no-cache openssh openssh-server  # Alpine example

# Configure SSH
RUN echo "PermitRootLogin yes" >> /etc/ssh/sshd_config

# Set root password
RUN echo "root:${SSH_PASSWORD}" | chpasswd

EXPOSE 22

CMD ["/usr/sbin/sshd", "-D"]
```

## Troubleshooting

### Rate Limiting

If you hit Docker Hub rate limits:

```bash
# Use authenticated requests (built-in)
make build-v2

# Wait for rate limit reset
sleep 3600
make build-v2
```

### Disk Space

If you run out of disk space:

```bash
# Clean Docker
docker system prune -af --volumes

# Run with automatic cleanup (built-in)
make build-v2
```

### Network Issues

If you have network issues:

```bash
# Check DNS
cat /etc/resolv.conf

# View detailed logs
tail -f logs/build.log
```

### Tags Not Found

If tags aren't being fetched:

```bash
# Clear cache
rm output/tags_cache.json

# Retry
make build-v2
```

## Requirements

- bash 4.0+
- docker
- curl (for API calls)
- Optional: jq (for JSON output)

## License

MIT License
