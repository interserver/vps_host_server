#!/bin/bash
#
# tmux wrapper for split window display
# Creates multiple panes: one for summary, others for parallel builds
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PARALLELISM="${PARALLELISM:-6}"

# Check if tmux is available
if ! command -v tmux &> /dev/null; then
    echo "tmux not found, installing..."
    apt-get update && apt-get install -y tmux
fi

# Kill any existing session
tmux kill-session -t docker-ssh-builder 2>/dev/null || true

# Create new session
tmux new-session -d -s docker-ssh-builder -n "summary"

# Set up summary window
tmux select-pane -t "docker-ssh-builder:0"
tmux send-keys "watch -n 2 '$SCRIPT_DIR/summary.sh'" Enter

# Create panes for parallel builds
for i in $(seq 1 $PARALLELISM); do
    tmux split-window -h -t "docker-ssh-builder:0"
    tmux select-layout -t "docker-ssh-builder:0" tiled
done

# Attach to session
tmux attach-session -t docker-ssh-builder

# When detaching, run the main script
tmux send-keys -t "docker-ssh-builder:0.0" "exit" Enter
tmux send-keys -t "docker-ssh-builder:0" "$SCRIPT_DIR/build_all_images.sh" Enter
