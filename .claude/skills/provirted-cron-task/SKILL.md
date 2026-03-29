---
name: provirted-cron-task
description: Adds or extends a `provirted.phar cron` subcommand invocation in `vps_cron.sh` or `qs_cron.sh`. Understands the cron lock pattern (`/dev/shm/lock`), age-based stale-check with `.cron.age`, `.enable_workerman` flag, and log append to `cron.output`. Use when asked to 'add a cron step', 'schedule a task', 'run something in the vps cron', or modify `vps_cron.sh`/`qs_cron.sh`. Do NOT use for workerman timer-based tasks (setupTimers.php).
---
# provirted-cron-task

## Critical

- **Never** add steps outside the `if [ $old_cron -eq 1 ]; then … else` block — the guard at the top of both scripts exits early for `/dev/shm/lock` and defers to workerman when `.enable_workerman` exists.
- **Never** modify the lock/age/workerman guard block (lines 9–36 in both scripts). Only add inside the `else` branch of the `$count -ge 2` check.
- All new invocations **must** append to `$log` (`>> $log 2>&1`) — never redirect to a new file unless the task is a background fire-and-forget (pattern: `2>$dir/cron.<name> >&2 &`).
- `qs_cron.sh` passes `-a` to every `provirted.phar cron` call; `vps_cron.sh` does not. Match the existing flag style for the target script.
- Do NOT reference anything under `unused/` — those scripts are deprecated.

## Instructions

1. **Identify the target script.** VPS tasks → `vps_cron.sh`; QuickServer tasks → `qs_cron.sh`. Read the file before editing.
   - Verify: `grep 'provirted.phar cron' vps_cron.sh` lists the existing steps (`host-info`, `bw-info`, `vps-info`, `cpu-usage`).

2. **Determine task type.**
   - *Standard (blocking)*: runs sequentially, output appended to `$log`. Template:
     ```bash
     $dir/provirted.phar cron <subcommand> >> $log 2>&1
     ```
     For `qs_cron.sh`, append `-a`:
     ```bash
     $dir/provirted.phar cron <subcommand> -a >> $log 2>&1
     ```
   - *Background (non-blocking, OpenVZ only)*: fires in background with separate log. Template:
     ```bash
     if [ -e /proc/vz ]; then
         $dir/provirted.phar cron <subcommand> 2>$dir/cron.<subcommand> >&2 &
     fi;
     ```
   - *Conditional (flag-gated)*: wrap in a guard. Example from `qs_cron.sh`:
     ```bash
     if [ ! -e /root/_disable<feature> ]; then
         $dir/provirted.phar cron <subcommand> -a >> $log 2>&1
     fi
     ```

3. **Choose insertion point** inside the `else` branch (after `touch .cron.age` / `echo … Crontab Startup`):
   - *Before queue fetches*: metadata/info tasks (like `host-info`) go near the top.
   - *After `bw-info`, before `vps-info`*: bandwidth/network tasks.
   - *After `vps-info`*: list/sync tasks that depend on the full VPS state.
   - Keep `/bin/rm -f $dir/cron.cmd;` as the **last** line inside `else`.

4. **Insert the new step** using Edit. Example — adding `cpu-usage` as a standard step to `vps_cron.sh` after `host-info`:
   ```bash
   $dir/provirted.phar cron host-info >> $log 2>&1
   $dir/provirted.phar cron cpu-usage >> $log 2>&1
   ```

5. **Validate** the edit:
   ```bash
   bash -n vps_cron.sh   # syntax check — must print nothing
   grep 'provirted.phar cron' vps_cron.sh   # confirm new line present
   ```

## Examples

**User says**: "Add a `vps-usage` cron step to `vps_cron.sh`, run it after `bw-info`."

**Actions taken**:
1. Read `vps_cron.sh` to confirm existing step order.
2. Edit: insert after `$dir/provirted.phar cron bw-info >> $log 2>&1`:
   ```bash
   $dir/provirted.phar cron vps-usage >> $log 2>&1
   ```
3. Run `bash -n vps_cron.sh` — no output confirms valid syntax.

**Result** — inside the `else` branch the sequence becomes:
```bash
$dir/provirted.phar cron host-info >> $log 2>&1
# … curl get_new_vps block …
$dir/provirted.phar cron bw-info >> $log 2>&1
$dir/provirted.phar cron vps-usage >> $log 2>&1
# … curl get_queue block …
$dir/provirted.phar cron vps-info >> $log 2>&1
```

## Common Issues

- **Task runs every invocation even when workerman is active**: you placed the step outside the `if [ $old_cron -eq 1 ]` block. Move it inside.
- **`bash -n` reports `unexpected end of file`**: an `if [ -e … ]; then` block is missing its `fi`. Count `if`/`fi` pairs around your edit.
- **Output not appearing in `cron.output`**: redirection is wrong. Must be `>> $log 2>&1`, not `> $log` (overwrites) or `2>&1 >> $log` (wrong order).
- **Step runs even when another cron is already running**: you inserted above the `$count -ge 2` / `else` split. All new steps must be inside the `else` branch.
- **`qs_cron.sh` step ignored by server**: missing `-a` flag. Every `provirted.phar cron` call in `qs_cron.sh` requires `-a` as the last argument.