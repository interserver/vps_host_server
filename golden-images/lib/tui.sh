#!/usr/bin/env bash
# lib/tui.sh — Real-time ANSI terminal dashboard (no tmux required)

# ── TUI State ───────────────────────────────────────────────────────────────
_TUI_ACTIVE=false

gb_tui_loop() {
  local wd="$1"
  gb_config_load_runtime "$wd"

  local total_jobs wall_start
  total_jobs="$(cat "$GB_WORK_DIR/.total_jobs" 2>/dev/null || echo 0)"
  wall_start="$(cat "$GB_WORK_DIR/.wall_start" 2>/dev/null || date +%s)"

  # Save terminal state
  _TUI_ACTIVE=true
  tput smcup 2>/dev/null || true   # Alternate screen buffer
  tput civis 2>/dev/null || true   # Hide cursor
  stty -echo 2>/dev/null || true
  trap '_tui_cleanup' EXIT INT TERM

  while true; do
    _tui_draw "$total_jobs" "$wall_start"

    # Non-blocking input check (1.5s refresh)
    if read -t 1.5 -n 1 key 2>/dev/null; then
      case "${key,,}" in
        q) break ;;
      esac
    fi

    # Check if all workers are finished
    if _tui_all_done; then
      _tui_draw "$total_jobs" "$wall_start"
      _tui_final_screen "$total_jobs" "$wall_start"
      # Wait for keypress then exit
      read -n 1 2>/dev/null || sleep 10
      break
    fi
  done

  _tui_cleanup
}

_tui_cleanup() {
  [[ "$_TUI_ACTIVE" == "true" ]] || return 0
  _TUI_ACTIVE=false
  tput rmcup 2>/dev/null || true   # Restore screen
  tput cnorm 2>/dev/null || true   # Show cursor
  stty echo 2>/dev/null || true
}

_tui_all_done() {
  for sf in "$GB_STATUS_DIR"/worker-*; do
    [[ -f "$sf" ]] || continue
    local st
    st="$(cat "$sf" 2>/dev/null || echo "")"
    case "$st" in
      done*|finished*) ;;
      *) return 1 ;;
    esac
  done
  # Need at least one worker to have started
  [[ -f "$GB_STATUS_DIR"/worker-1 ]] || return 1
  return 0
}

_tui_draw() {
  local total_jobs="$1" wall_start="$2"
  local cols rows
  cols="$(tput cols 2>/dev/null || echo 80)"
  rows="$(tput lines 2>/dev/null || echo 24)"

  local now elapsed_s elapsed_m elapsed_h
  now=$(date +%s)
  elapsed_s=$(( now - wall_start ))
  elapsed_m=$(( elapsed_s / 60 ))
  elapsed_h=$(( elapsed_s / 3600 ))
  local elapsed_fmt
  elapsed_fmt="$(printf '%02d:%02d:%02d' "$elapsed_h" "$((elapsed_m % 60))" "$((elapsed_s % 60))")"

  local ok fail pending active completed pct
  ok=$(gb_queue_count_ok)
  fail=$(gb_queue_count_fail)
  pending=$(gb_queue_count_pending)
  active=$(gb_queue_count_active)
  completed=$((ok + fail))
  pct=0
  [[ "$total_jobs" -gt 0 ]] && pct=$(( completed * 100 / total_jobs ))

  # Move cursor to top-left, draw frame
  printf '\033[H'

  # ── Header ──
  local hdr
  hdr="$(printf ' Golden Build v%s' "$GB_VERSION")"
  local hdr_right
  hdr_right="$(printf 'Elapsed: %s ' "$elapsed_fmt")"
  printf '\033[7m'  # Reverse video
  printf '%-*s%s' "$((cols - ${#hdr_right}))" "$hdr" "$hdr_right"
  printf '\033[0m\n'

  # ── Progress bar ──
  local bar_width=$((cols - 30))
  [[ $bar_width -lt 20 ]] && bar_width=20
  local filled=$(( pct * bar_width / 100 ))
  local empty=$(( bar_width - filled ))
  printf '  %s' "$GB_G"
  for ((i=0; i<filled; i++)); do printf '#'; done
  printf '%s' "$GB_RST"
  for ((i=0; i<empty; i++)); do printf '.'; done
  printf '  %3d%%  %d/%d\033[K\n' "$pct" "$completed" "$total_jobs"

  # ── Stats line ──
  printf '  %sOK:%s %-4d  %sFAIL:%s %-4d  %sACTIVE:%s %-3d  %sPEND:%s %-4d' \
    "$GB_G" "$GB_RST" "$ok" \
    "$GB_R" "$GB_RST" "$fail" \
    "$GB_C" "$GB_RST" "$active" \
    "$GB_DIM" "$GB_RST" "$pending"

  # Rate limit status
  if [[ -f "$GB_WORK_DIR/.rate_status" ]]; then
    local rstatus
    rstatus="$(cat "$GB_WORK_DIR/.rate_status" 2>/dev/null || echo "?/?")  "
    printf '  %sRate:%s %s' "$GB_Y" "$GB_RST" "$rstatus"
  fi

  # Disk space
  local avail
  avail="$(gb_disk_avail_mb "$GB_WORK_DIR" 2>/dev/null || echo "?")"
  printf '  %sDisk:%s %sMB free' "$GB_DIM" "$GB_RST" "$avail"
  printf '\033[K\n'

  # ── Separator ──
  printf '  '
  for ((i=0; i<cols-4; i++)); do printf '-'; done
  printf '\033[K\n'

  # ── Worker status ──
  printf '  %sWorkers:%s\033[K\n' "$GB_BOLD" "$GB_RST"
  for sf in "$GB_STATUS_DIR"/worker-*; do
    [[ -f "$sf" ]] || continue
    local wname status_text status_color
    wname="$(basename "$sf")"
    status_text="$(cat "$sf" 2>/dev/null || echo "unknown")"
    status_color="$GB_C"
    case "$status_text" in
      done*|finished*) status_color="$GB_G" ;;
      rate-limited*)   status_color="$GB_Y" ;;
      retry-wait*)     status_color="$GB_Y" ;;
      idle)            status_color="$GB_DIM" ;;
    esac
    printf '   %s%-10s%s %s%s%s\033[K\n' "$GB_B" "$wname" "$GB_RST" "$status_color" "$status_text" "$GB_RST"
  done

  # ── Separator ──
  printf '  '
  for ((i=0; i<cols-4; i++)); do printf '-'; done
  printf '\033[K\n'

  # ── Recent results ──
  printf '  %sRecent:%s\033[K\n' "$GB_BOLD" "$GB_RST"
  local shown=0
  local max_show=$(( rows - 14 - GB_PARALLELISM ))
  [[ $max_show -lt 3 ]] && max_show=3
  [[ $max_show -gt 12 ]] && max_show=12

  for rf in $(ls -t "$GB_RESULTS_DIR"/*.ok "$GB_RESULTS_DIR"/*.fail 2>/dev/null | head -"$max_show"); do
    [[ -f "$rf" ]] || continue
    local rname rtype
    rname="$(basename "$rf")"
    rtype="${rname##*.}"
    rname="${rname%.*}"
    if [[ "$rtype" == "ok" ]]; then
      printf '   %s OK %s %s\033[K\n' "$GB_G" "$GB_RST" "$rname"
    else
      local reason=""
      [[ -f "$rf" ]] && reason="$(cut -f1 < "$rf" 2>/dev/null || true)"
      printf '   %s  X %s %s %s(%s)%s\033[K\n' "$GB_R" "$GB_RST" "$rname" "$GB_DIM" "$reason" "$GB_RST"
    fi
    shown=$((shown + 1))
  done
  [[ $shown -eq 0 ]] && printf '   %s(waiting for results...)%s\033[K\n' "$GB_DIM" "$GB_RST"

  # ── Fill remaining lines ──
  local used=$(( 6 + GB_PARALLELISM + 2 + shown + 2 ))
  for ((i=used; i<rows-1; i++)); do
    printf '\033[K\n'
  done

  # ── Status bar ──
  printf '\033[7m'
  printf ' q=quit  '
  printf '%-*s' "$((cols - 10))" " golden-build v${GB_VERSION}"
  printf '\033[0m'
}

_tui_final_screen() {
  local total_jobs="$1" wall_start="$2"
  local cols
  cols="$(tput cols 2>/dev/null || echo 80)"

  printf '\n'
  printf '  %s ALL BUILDS COMPLETE %s\033[K\n' "$GB_BG_G" "$GB_RST"
  printf '\033[K\n'

  local ok fail
  ok=$(gb_queue_count_ok)
  fail=$(gb_queue_count_fail)
  local elapsed_s=$(( $(date +%s) - wall_start ))
  printf '  Results: %s%d succeeded%s, %s%d failed%s in %dm %ds\033[K\n' \
    "$GB_G" "$ok" "$GB_RST" "$GB_R" "$fail" "$GB_RST" \
    $((elapsed_s / 60)) $((elapsed_s % 60))
  printf '\033[K\n'
  printf '  Reports saved to: %s/\033[K\n' "$GB_WORK_DIR"
  printf '  %sPress any key to exit...%s\033[K\n' "$GB_DIM" "$GB_RST"
}
