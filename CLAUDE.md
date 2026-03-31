# vps_host_server

VPS Hosting Server Daemon — provisions, monitors, and communicates with `myvps.interserver.net`.

## Architecture

- **Primary CLI**: `provirted.phar` — all VPS lifecycle ops, VNC setup, cron tasks
- **Daemon**: `workerman/` (Workerman 4.x, PHP ≥5.3) · entry: `workerman/start.php` · namespace: `MyAdmin\VpsHost\`
- **Cron**: `vps_cron.sh` (VPS) · `qs_cron.sh` (QuickServers) · `vps_cron_daily.php`
- **Workers**: `workerman/src/Workers/VpsServer.php` · `workerman/src/Workers/Task.php` · `workerman/src/Workers/GlobalData.php`
- **Events**: `workerman/src/Events/` — `onMessage.php` · `onWorkerStart.php` · `setupTimers.php` · `onConnect.php` · `onClose.php`
- **Tasks**: `workerman/src/Tasks/vps_queue.php` · `workerman/src/Tasks/vps_get_list.php` · `workerman/src/Tasks/vps_update_info.php`
- **Config**: `workerman/src/Config/settings.php` · `workerman/config.ini.dist`
- **Stats**: `workerman/src/Stats/Network.php` · `workerman/src/Stats/Storage.php` · `workerman/src/Stats/System.php`
- **Golden Images**: `golden-images/` — builds and verifies OS base images for VPS provisioning

## Virtualization Support

| Type | Key Scripts |
|------|-------------|
| KVM | `templates/install_kvm.sh`, `vps_kvm_lvmcreate.sh`, `vps_kvm_lvmresize.sh`, `windows.xml` |
| LXD | `templates/install_lxd.sh`, `templates/test_lxd.sh` |
| Virtuozzo | `templates/install_virtuozzo.sh`, `templates/test_virtuozzo.sh` |
| OpenVZ | `vzenable`, `vzopenvztc.sh` |

Storage backend: `vz` pool — either LVM (`/dev/vz/<vps>`) or ZFS (`/vz/<vps>/os.qcow2`). Bootstrap with `create_libvirt_storage_pools.sh`.

## Common Commands

```bash
# Workerman daemon
workerman/start.php start|stop|restart|status -d
workerman/update.sh

# Cron (run manually)
./vps_cron.sh
./qs_cron.sh

# provirted.phar
./provirted.phar vnc setup <vps> <client_ip>
./provirted.phar cron host-info
./provirted.phar cron bw-info
./provirted.phar cron vps-info
./provirted.phar cron cpu-usage

# KVM storage
./vps_kvm_lvmcreate.sh <name> <size_mb>
./create_libvirt_storage_pools.sh

# Networking
./run_buildebtables.sh
./tclimit <ip> <vnet_iface> <vps_id>
./vps_refresh_vnc.sh <vps>
./vps_refresh_all_vnc.sh
./set_io_limits.sh

# Golden Images
./golden-images/golden-build          # main image build CLI
./golden-images/build_all.sh          # build all images from images.matrix
./golden-images/verify_image.sh       # verify a built image
./golden-images/dockerfarm.sh         # distributed docker build farm
./golden-images/render_dockerfile.sh  # render Dockerfile from distro.d/ template
```

## Key Conventions

- **Daemon toggle**: touch `.enable_workerman` in project root to switch from cron-only to Workerman mode; `vps_cron.sh` checks this flag
- **DHCP file**: `/etc/dhcp/dhcpd.vps` or `/etc/dhcpd.vps` — MAC→IP mappings for all VPS
- **VNC map**: `vps.vncmap` — maps VPS id → client IP for VNC proxying via `provirted.phar`
- **Cron log**: `cron.output` — all cron run output
- **VPS naming**: `vps{id}`, `windows{id}`, `linux{id}`, `qs{id}`
- **MAC prefix**: `00:16:3E` for VPS, `00:0C:29` for QuickServers (see `unused/convert_id_to_mac.sh`)
- **Central API**: `https://myvps.interserver.net/vps_queue.php` · `https://myvps.interserver.net/qs_queue.php` · `http://myvps.interserver.net:55151/queue.php`
- **Unused**: `unused/` contains deprecated scripts — do not reference or restore them
- **Templates dir**: `templates/` — install and test scripts per virt type; `templates/test_*.sh` for smoke testing
- **XML utility**: `xml2array.php` (root) and `workerman/src/Data/xml2array.php` — used to parse `virsh dumpxml` output
- **KVM template XML**: `windows.xml` — base libvirt domain config cloned for new VPS
- **Golden Images**: `golden-images/images.matrix` defines build targets; `golden-images/distro.d/` contains per-distro build scripts; `golden-images/golden-build.conf.default` is the config template

## Workerman Patterns

```php
// New worker: add PHP file to workerman/src/Workers/
// New event handler: add PHP file to workerman/src/Events/
// New background task: add PHP file to workerman/src/Tasks/
// Bootstrap runs workerman/src/bootstrap.php then globs Workers/*.php
// Composer autoload: MyAdmin\VpsHost\ -> workerman/src/
```

Install deps: `cd workerman && composer install --no-dev`

## RAID Monitoring

`nagios-plugin-check_raid/` — Perl submodule. Entry: `nagios-plugin-check_raid/bin/check_raid.pl` · wrapper: `nagios-plugin-check_raid/check_raid.sh`

```bash
# Run check
nagios-plugin-check_raid/check_raid.sh --check=WARNING

# Run tests
cd nagios-plugin-check_raid && make test
```

Test data under `nagios-plugin-check_raid/t/data/`. Lib code in `nagios-plugin-check_raid/lib/App/Monitoring/`.


## Before Committing

Run `caliber refresh` before creating git commits to keep docs in sync with code changes.
After it completes, stage any modified doc files before committing:

```bash
caliber refresh && git add CLAUDE.md .claude/ .cursor/ .github/copilot-instructions.md AGENTS.md CALIBER_LEARNINGS.md 2>/dev/null
```
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.

<!-- caliber:managed:pre-commit -->
## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
<!-- /caliber:managed:sync -->
