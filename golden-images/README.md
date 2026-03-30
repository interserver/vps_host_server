# golden-build

Single-command tool for building SSH-enabled Docker golden images across all major Linux distributions.

## Quick Start

```bash
cd golden-images
./golden-build setup    # Check deps, create config
./golden-build          # Build everything
```

## Architecture

```
golden-build              # Single CLI entry point
lib/
  core.sh                 # Colors, logging, utilities
  config.sh               # Configuration loading
  registry.sh             # Docker Hub auth + token caching
  tui.sh                  # Real-time ANSI TUI dashboard
  queue.sh                # Atomic file-based job queue
  worker.sh               # Build worker logic
  dockerfile.sh           # Dockerfile generation (loads plugins)
  verify.sh               # SSH verification (OpenSSH + dropbear)
  report.sh               # JSON + text reporting + error aggregation
distro.d/                 # Per-distro plugin handlers
  ubuntu.sh debian.sh fedora.sh alpine.sh rhel.sh
  arch.sh photon.sh mageia.sh busybox.sh cirros.sh
```

## Features

### Per-Distro Plugin Handlers
Each distro family has its own plugin in `distro.d/`. Plugins define:
- `PLUGIN_FAMILIES` — which image families the plugin handles
- `PLUGIN_SSH_TYPE` — `openssh` or `dropbear`
- `plugin_fix_repos()` — auto-patches broken/EOL repos
- `plugin_install_ssh()` — installs the SSH server

To add a new distro, create a file in `distro.d/` following the pattern.

### Real-time TUI Dashboard
Full-screen ANSI terminal UI — no tmux required:
- Live progress bar with percentage
- Per-worker status display
- Recent build results
- Disk space and rate limit monitoring
- Press `q` to quit

### Docker Registry Auth
Authenticated pulls get 2x rate limits (200 vs 100 pulls/6h):
```bash
export DOCKER_USERNAME=myuser
export DOCKER_PASSWORD=mytoken
./golden-build
```
Tokens are cached (5-min TTL) to minimize API calls.

### Structured JSON Output
```bash
./golden-build --json
cat build/report.json
```
Produces machine-readable output with config, summary, succeeded/failed arrays.

### Auto-Patching Broken Repos
Handled per-distro in plugins:
- **Ubuntu EOL** → `old-releases.ubuntu.com`
- **Debian EOL** → `archive.debian.org`
- **CentOS 8** → `vault.centos.org`
- **Fedora EOL** → `archives.fedoraproject.org`
- **Scientific Linux** → fixes mirror URLs
- **Alpine old** → pins CDN URLs
- **Arch** → refreshes keyring

### Error Handling
- **Rate limiting**: Detects 429/toomanyrequests, backs off with Retry-After or 15m default
- **Network errors**: Retries on connection reset, TLS timeout, TCP timeout, i/o timeout, deadline exceeded
- **Schema v1**: Auto-skips deprecated manifest format
- **Disk space**: Monitors free space, triggers `docker system prune` when low

## Commands

| Command | Description |
|---------|-------------|
| `golden-build run` | Build all images (default) |
| `golden-build setup` | Check dependencies, create config |
| `golden-build status` | Show last build results |
| `golden-build report` | Generate full report from last build |
| `golden-build clean` | Remove build artifacts |

## Options

| Flag | Description |
|------|-------------|
| `-p, --parallel N` | Parallel workers (default: 6) |
| `-m, --matrix FILE` | Matrix file (default: images.matrix) |
| `-c, --config FILE` | Config file |
| `--tui / --no-tui` | Force/disable TUI dashboard |
| `--json` | Generate JSON report |
| `--push` | Push images after build |
| `--no-verify` | Skip SSH verification |
| `--clean` | Clean before building |

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `GB_PARALLELISM` | `6` | Concurrent builds |
| `GB_ROOT_PASSWORD` | `InterServer!23` | Root password |
| `GB_REGISTRY_PREFIX` | `interserver` | Tag prefix |
| `DOCKER_USERNAME` | _(none)_ | Docker Hub user (2x rate limit) |
| `DOCKER_PASSWORD` | _(none)_ | Docker Hub password/token |
| `GB_MAX_RETRIES` | `3` | Retry attempts |
| `GB_RATE_LIMIT_WAIT` | `900` | Rate limit backoff (seconds) |
| `GB_MIN_DISK_MB` | `3000` | Prune threshold |

## Supported Distros (14 families)

| Family | SSH Server | Auto-Fixes |
|--------|-----------|------------|
| Ubuntu | OpenSSH | EOL → old-releases |
| Debian | OpenSSH | EOL → archive.debian.org |
| Fedora | OpenSSH | EOL → archives.fedoraproject.org |
| Alpine | OpenSSH | Old repo URLs |
| Oracle Linux | OpenSSH | OL7 repo enable |
| AlmaLinux | OpenSSH | — |
| Rocky Linux | OpenSSH | — |
| Amazon Linux | OpenSSH | AL2 repo fix |
| Scientific Linux | OpenSSH | Mirror URL fix |
| CentOS | OpenSSH | CentOS 8 → vault |
| Arch Linux | OpenSSH | Keyring refresh |
| Photon | OpenSSH | — |
| Mageia | OpenSSH | dnf/urpmi fallback |
| Busybox | Dropbear | Multi-stage from Alpine |
| Cirros | Dropbear | Built-in dropbear config |

## Legacy Scripts

The previous `build_all.sh`, `render_dockerfile.sh`, and `verify_image.sh` are still present for backwards compatibility. `golden-build` is the recommended entry point going forward.

## Output Files

| File | Description |
|------|-------------|
| `build/good_templates.txt` | Successfully built + verified images |
| `build/bad_templates.txt` | Failed images with reason |
| `build/report.json` | Structured JSON report (with `--json`) |
| `build/logs/*.log` | Per-image build logs |
