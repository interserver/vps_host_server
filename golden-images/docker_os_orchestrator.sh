#!/bin/bash
# ==============================================================================
# Docker OS Template Builder & Validator (v2 - Fixed)
# ==============================================================================

# --- CONFIGURATION ---
MAX_PARALLEL_JOBS=6
TARGET_OS_LIST=("busybox" "ubuntu" "fedora" "debian" "cirros" "mageia" "oraclelinux" "alpine" "photon" "amazonlinux" "archlinux" "almalinux" "rockylinux")
VERSIONS_PER_OS=15
ROOT_PASS="InterServer!23"
USE_API=true 
DOCKER_REGISTRY="docker.io"
REGISTRY_NAMESPACE="library"
LOG_DIR="./build_logs"
RESULTS_FILE="results.json"

# --- COLORS (Only for main thread) ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# --- GLOBALS ---
declare -A GOOD_TEMPLATES
declare -A BAD_TEMPLATES
TOTAL_BUILDS=0
COMPLETED_BUILDS=0
RUNNING_JOBS=0

# ==============================================================================
# HELPER FUNCTIONS
# ==============================================================================

log() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

# ==============================================================================
# DISCOVERY ENGINE
# ==============================================================================

get_tags() {
    local os=$1
    local tags=()
    
    if [ "$USE_API" = true ]; then
        log "Discovering tags for $os via API..."
        
        # Fetch Token (with error handling)
        local token_response=$(curl -s -w "\n%{http_code}" "https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/${os}:pull")
        local http_code=$(echo "$token_response" | tail -n1)
        local token_body=$(echo "$token_response" | sed '$d')
        
        if [ "$http_code" -ne 200 ]; then
            log_error "Failed to get token for $os (HTTP $http_code). Using fallback."
            return 1
        fi

        local token=$(echo "$token_body" | jq -r .token 2>/dev/null)
        
        if [ -z "$token" ] || [ "$token" == "null" ]; then
            log_error "Token is empty for $os"
            return 1
        fi

        # Fetch Tags
        local response=$(curl -s -H "Authorization: Bearer $token" "https://registry.hub.docker.com/v2/library/${os}/tags?page_size=100")
        
        # Check if response is valid JSON
        if ! echo "$response" | jq -e . > /dev/null 2>&1; then
            log_error "Invalid JSON response for $os tags"
            return 1
        fi

        # Filter and Sort
        # We try to find version tags (X.Y or X.Y.Z)
        tags=$(echo "$response" | jq -r '.results[] | .name' | grep -E '^[0-9]+\.[0-9]+' | sort -V | tail -n $VERSIONS_PER_OS)
        
        # Fallback if no semver tags found (e.g. alpine, debian might use just numbers)
        if [ -z "$tags" ]; then
             tags=$(echo "$response" | jq -r '.results[] | .name' | grep -E '^[0-9]+' | sort -V | tail -n $VERSIONS_PER_OS)
        fi
        
        # Fallback for busybox or others
        if [ -z "$tags" ]; then
             tags=$(echo "$response" | jq -r '.results[] | .name' | head -n $VERSIONS_PER_OS)
        fi
    else
        # Hardcoded Fallback
        case $os in
            ubuntu) echo "22.04 20.04 18.04 23.04 24.04 21.04 21.10" ;;
            alpine) echo "3.18 3.17 3.16 3.15 3.14 3.19" ;;
            debian) echo "12 11 10 13" ;;
            fedora) echo "38 39 40 37 36" ;;
            rockylinux) echo "9 8.8 8.7 8.6 8" ;;
            almalinux) echo "9.3 9.2 9.1 9.0 8.8 8.7 8" ;;
            *) echo "latest" ;;
        esac
        return 0
    fi
    
    echo "$tags"
}

# ==============================================================================
# DOCKERFILE GENERATOR
# ==============================================================================

generate_dockerfile() {
    local os=$1
    local ver=$2
    local df_path=$3
    
    cat > "$df_path" <<EOF
# syntax=docker/dockerfile:1
FROM ${DOCKER_REGISTRY}/${REGISTRY_NAMESPACE}/${os}:${ver}

EOF

    case $os in
        alpine)
            cat >> "$df_path" <<'EOF'
RUN apk add --no-cache openssh-server shadow && \
    ssh-keygen -A
EOF
            ;;
        ubuntu|debian)
            cat >> "$df_path" <<'EOF'
RUN apt-get update && apt-get install -y --no-install-recommends \
    openssh-server \
    && rm -rf /var/lib/apt/lists/*
RUN mkdir /var/run/sshd
EOF
            ;;
        fedora|rockylinux|almalinux|oraclelinux|amazonlinux)
            cat >> "$df_path" <<'EOF'
# Fix repos
RUN if [ -f /etc/yum.repos.d/*.repo ]; then \
      sed -i 's|mirrorlist=|#mirrorlist=|g; s|#baseurl=http://mirror.centos.org|baseurl=http://vault.centos.org|g' /etc/yum.repos.d/*.repo || true; \
    fi
RUN dnf install -y openssh-server && dnf clean all
RUN ssh-keygen -A
EOF
            ;;
        photon)
            cat >> "$df_path" <<'EOF'
RUN tdnf install -y openssh-server && tdnf clean all
RUN ssh-keygen -A
EOF
            ;;
        mageia)
             cat >> "$df_path" <<'EOF'
RUN urpmi.update -a && urpmi --auto openssh-server && urpmi --auto-clean
RUN ssh-keygen -A
EOF
            ;;
        archlinux)
            cat >> "$df_path" <<'EOF'
RUN pacman -Sy --noconfirm openssh && pacman -Scc --noconfirm
RUN ssh-keygen -A
EOF
            ;;
        *)
            cat >> "$df_path" <<'EOF'
RUN yum install -y openssh-server || apt-get install -y openssh-server || apk add openssh-server
RUN ssh-keygen -A
EOF
            ;;
    esac

    cat >> "$df_path" <<EOF
RUN echo 'root:${ROOT_PASS}' | chpasswd
EXPOSE 22
CMD ["/usr/sbin/sshd", "-D"]
EOF
}

# ==============================================================================
# BUILD WORKER (Runs in background)
# ==============================================================================

process_single_build() {
    local os=$1
    local ver=$2
    local build_dir="./builds/${os}-${ver}"
    local dockerfile="${build_dir}/Dockerfile"
    local log_file="${LOG_DIR}/${os}-${ver}.log"
    
    mkdir -p "$build_dir" "$LOG_DIR"
    
    # Simple log to file (no colors in background)
    echo "[$(date)] Building ${os}:${ver}..." > "$log_file"
    
    # Generate DF
    generate_dockerfile "$os" "$ver" "$dockerfile" >> "$log_file" 2>&1
    
    # Build
    local build_cmd="docker build -t ${os}-${ver}:latest -f ${dockerfile} ${build_dir} --network=host"
    
    local attempt=1
    local max_attempts=3
    local success=false
    
    while [ $attempt -le $max_attempts ]; do
        $build_cmd >> "$log_file" 2>&1
        local exit_code=$?
        
        if [ $exit_code -eq 0 ]; then
            success=true
            break
        fi
        
        local last_lines=$(tail -n 20 "$log_file")
        
        if echo "$last_lines" | grep -q "429\|Too Many Requests"; then
            echo "[$(date)] Rate Limited. Backing off 60s..." >> "$log_file"
            sleep 60
        elif echo "$last_lines" | grep -q "connection reset\|TLS handshake timeout\|dial tcp\|i/o timeout\|deadline exceeded"; then
            echo "[$(date)] Network Timeout. Retrying in 10s..." >> "$log_file"
            sleep 10
        elif echo "$last_lines" | grep -q "no space left on device"; then
            echo "[$(date)] Disk Full. Cleaning up..." >> "$log_file"
            docker system prune -af --volumes >> "$log_file" 2>&1
            sleep 5
        else
            echo "[$(date)] Build failed with exit code $exit_code" >> "$log_file"
        fi
        
        attempt=$((attempt + 1))
    done
    
    if [ "$success" = true ]; then
        # Verify SSH
        local container_id=$(docker run -d -P ${os}-${ver}:latest 2>>"$log_file")
        if [ -z "$container_id" ]; then
             echo "[$(date)] Failed to start container" >> "$log_file"
             BAD_TEMPLATES["${os}:${ver}"]="Container Start Failed"
             return 1
        fi
        
        sleep 3
        local port=$(docker port $container_id 22 2>/dev/null | cut -d':' -f2)
        
        if [ -n "$port" ] && nc -z localhost $port 2>/dev/null; then
            echo "[$(date)] SUCCESS: SSH Open on port $port" >> "$log_file"
            GOOD_TEMPLATES["${os}:${ver}"]="Port: $port"
        else
            echo "[$(date)] FAIL: SSH Connection Failed" >> "$log_file"
            BAD_TEMPLATES["${os}:${ver}"]="SSH Failed"
        fi
        
        docker stop $container_id > /dev/null 2>&1
        docker rm $container_id > /dev/null 2>&1
    else
        echo "[$(date)] FAILED after retries" >> "$log_file"
        BAD_TEMPLATES["${os}:${ver}"]="Build Failed"
    fi
}

# ==============================================================================
# ORCHESTRATION
# ==============================================================================

run_orchestrator() {
    mkdir -p builds
    touch /tmp/results_buffer.json
    
    log "Starting Discovery Phase..."
    
    # 1. Discover Versions
    declare -A VERSIONS_TO_BUILD
    
    for os in "${TARGET_OS_LIST[@]}"; do
        log "Processing OS: $os"
        
        # Get tags, handle potential failure
        local tags
        tags=$(get_tags "$os") || tags="latest" # Fallback
        
        local count=0
        # Read tags into array safely
        while IFS= read -r tag; do
            if [ -z "$tag" ]; then continue; fi
            if [ $count -ge $VERSIONS_PER_OS ]; then break; fi
            
            VERSIONS_TO_BUILD["${os}:${tag}"]=1
            TOTAL_BUILDS=$((TOTAL_BUILDS + 1))
            count=$((count + 1))
        done <<< "$tags"
    done
    
    log "Found $TOTAL_BUILDS templates to build."
    
    # 2. Run Builds using Semaphore (Background Jobs)
    # This is more robust than xargs for bash functions
    
    for key in "${!VERSIONS_TO_BUILD[@]}"; do
        # Wait if we hit max parallel jobs
        while [ $RUNNING_JOBS -ge $MAX_PARALLEL_JOBS ]; do
            wait -n
            RUNNING_JOBS=$((RUNNING_JOBS - 1))
        done
        
        # Split key into os and ver
        os="${key%%:*}"
        ver="${key#*:}"
        
        # Launch background job
        process_single_build "$os" "$ver" &
        RUNNING_JOBS=$((RUNNING_JOBS + 1))
    done
    
    # Wait for remaining jobs
    wait
    
    log "========================================="
    log "BUILD SUMMARY"
    log "========================================="
    
    echo "Good Templates:"
    for k in "${!GOOD_TEMPLATES[@]}"; do
        echo -e "  ${GREEN}[OK]${NC} $k -> ${GOOD_TEMPLATES[$k]}"
    done
    
    echo "Bad Templates:"
    for k in "${!BAD_TEMPLATES[@]}"; do
        echo -e "  ${RED}[FAIL]${NC} $k -> ${BAD_TEMPLATES[$k]}"
    done
    
    log "Results saved to $RESULTS_FILE"
}

# --- MAIN ---
run_orchestrator
