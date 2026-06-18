# cloud-init template testing

End-to-end tester for the one-click app cloud-init templates in `cloudinit/`. It
installs each template on a throwaway test VPS exactly the way a **VPS reinstall**
does (same `provirted.phar create ... cloud-init:<base>.qcow2:<app>.yaml ...`
command), waits for the guest to boot + finish cloud-init, then logs in and runs
per-app health checks. Results are saved as JSON.

## Files

| File | Purpose |
|------|---------|
| `test_cloudinit_templates.sh` | Orchestrator. Runs on the **hypervisor host** (where `provirted` lives). One template at a time. |
| `cloudinit_remote_check.sh` | Runs **inside the guest**. Emits a JSON verdict for one template. Copied over automatically. |
| `cloudinit_tests.json` | Per-template expectations (services / ports / files / http / commands). Auto-derived from the yamls — **edit to tune**. |
| `cloudinit_test_results/<timestamp>/` | Output: `results.json`, `summary.txt`, and a `<app>.create.log` + `<app>.checks.json` per template. |

## One-time setup

The script needs a throwaway VPS slot and a routable test IP on the host. Put
overrides in `~/.provirted/cloudinit_test.env`:

```bash
TEST_VZID=vps999999
TEST_HOSTNAME=citest.trouble-free.net
TEST_IP=67.217.48.56            # REQUIRED: a real routable test IP on this host
TEST_IPV6_IP=2604:a00:50:11c:1::1
TEST_IPV6_RANGE=2604:a00:50:11c:1::/80
ROOT_PASSWORD='CiTest=Passw0rd!'
HD=40
RAM=4096
CPU=2
# default base image used when a template has no base_image override in the registry
DEFAULT_BASE=ubuntu24.qcow2
# login: key auth is preferred (public key is injected via --ssh-key at create).
SSH_KEY=$HOME/.ssh/id_ed25519   # private key; SSH_KEY.pub must exist
# (if no key pair exists it falls back to password auth, which needs `sshpass`)
```

A login key works without extra packages — generate one if needed:
`ssh-keygen -t ed25519 -f ~/.ssh/id_ed25519 -N ''`.

## Usage

```bash
# from /root/cpaneldirect (this dir) on the hypervisor:
./test_cloudinit_templates.sh                      # every template in the registry
./test_cloudinit_templates.sh openclaw wordpress   # just these
./test_cloudinit_templates.sh cloudinit/grafana.yaml
```

## What each template run does

1. Sync `cloudinit/<app>.yaml` → `/vz/templates/cloudinit/` (where provirted reads it).
2. `provirted stop -f` / `destroy` the test vzid (clean slate).
3. `provirted.phar create --virt=kvm ... cloud-init:<base>.qcow2:<app>.yaml <hd> <ram> <cpu>`.
4. Poll SSH until reachable (`BOOT_TIMEOUT`, default 600s).
5. `cloud-init status --wait` to let the app install finish (`CLOUDINIT_TIMEOUT`, default 2400s).
6. Run `cloudinit_remote_check.sh` in the guest → JSON verdict.
7. Record result; destroy the VPS (kept on failure if `KEEP_ON_FAIL=1`).

## Checks performed (per template)

Always:
- **cloud_init_status** — `cloud-init status` is `done` (degraded = advisory).
- **no_failed_units** — `systemctl --failed` is empty.
- **bootstrap_completed** — `/var/log/<app>-bootstrap.log` contains the
  `=== <app> bootstrap finished/complete ===` marker every template emits.
- **bootstrap_no_errors** — *advisory* scan of that log for `ERROR/FATAL/Traceback`.

Per-app, driven by `cloudinit_tests.json`:
- **service:`<name>`** — `systemctl is-active` (oneshot units that exited 0 count as ok).
- **port:`<n>`** — listening per `ss -ltn`.
- **file:`<path>`** — exists (e.g. the credentials file).
- **http:`<port><path>`** — `curl` returns `expect_code` and/or contains `expect_text`.
- **cmd:`<cmd>`** — runs in the guest and returns `expect_code` (default 0).

`overall_pass` = every **non-advisory** check passed.

## Tuning expectations

`cloudinit_tests.json` was auto-generated from the yamls, so `services`/`ports`
are best-effort. Edit any entry to make the test stronger. Example (`grafana`):

```json
"grafana": {
  "base_image": "",
  "services": ["grafana-server", "nginx"],
  "ports": [80, 3000],
  "http":     [{ "port": 3000, "path": "/login", "expect_code": 200, "expect_text": "Grafana" }],
  "commands": [{ "cmd": "test -s /root/grafana-credentials.txt", "expect_code": 0 }],
  "files": ["/root/grafana-credentials.txt"]
}
```

- Set `base_image` per template if it isn't the default (e.g. `almalinux9.qcow2`,
  `debian12.qcow2`). Empty = `defaults.base_image`.
- `http` checks hit `127.0.0.1` — only useful for services bound to localhost/all
  interfaces (not LAN-only binds).

## Results format

`cloudinit_test_results/<ts>/results.json` is an array of:

```json
{
  "template": "grafana",
  "status": "pass",                       // pass | fail | error
  "reason": "",                           // failing checks (when not pass)
  "template_arg": "cloud-init:ubuntu24.qcow2:grafana.yaml",
  "started": "...", "finished": "...",
  "create_log": ".../grafana.create.log",
  "checks": { ...full remote verdict... }
}
```

`status` meanings: `pass` = all checks ok; `fail` = installed but a check failed;
`error` = create/ssh/cloud-init never got far enough to check.
