#!/bin/bash
#
# Split window launcher using tmux
# Creates multiple panes: summary + parallel build windows
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARALLELISM="${PARALLELISM:-6}"

# Colors
GREEN='\033[0;32m'
CYAN='\033[0;36m'
NC='\033[0m'

echo -e "${CYAN}Starting Docker SSH Image Builder with split view...${NC}"

# Check/install tmux
if ! command -v tmux &> /dev/null; then
    echo "Installing tmux..."
    apt-get update && apt-get install -y tmux 2>/dev/null
fi

# Kill existing session
tmux kill-session -t docker-ssh 2>/dev/null || true

# Create session
tmux new-session -d -s docker-ssh -n "summary"

# Setup summary pane
tmux send-keys "clear; $SCRIPT_DIR/summary.sh" Enter

# Split horizontally for summary
tmux split-window -h -t docker-ssh

# Send to right pane (log tail)
tmux send-keys -t docker-ssh:0.1 "tail -f $SCRIPT_DIR/logs/build.log" Enter

# Create vertical splits for parallel views
for i in $(seq 2 $PARALLELISM); do
    tmux split-window -v -t docker-ssh:0
done

# Layout
tmux select-layout -t docker-ssh:0 even-vertical

# Attach
echo ""
echo -e "${GREEN}Launching tmux session 'docker-ssh'${NC}"
echo "Press Ctrl+B then D to detach"
echo "Use 'tmux attach -t docker-ssh' to reattach"
echo ""
echo "Starting build process..."
sleep 2

# Start the build in background, update summary pane
tmux send-keys -t docker-ssh:0.0 "cd $SCRIPT_DIR && ./build_images_enhanced.sh" Enter

# Attach to session
tmux attach-session -t docker-ssh
