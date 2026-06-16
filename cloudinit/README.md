# cloud-init app templates

One `#cloud-config` user-data file per "one-click app" image, our in-house equivalents of the
Vultr Marketplace catalog. Built from scratch on our own base images — no Vultr metadata/APIs.

## How these are used

A `vps_templates` row (DB `my`, `template_type=14` / KVMv2) with
`template_file = cloud-init:<base>.qcow2:<name>.yaml` points the hypervisor at one of these files.
`provirted.phar` clones `<base>.qcow2` and feeds the matching `<name>.yaml` here to the VM as NoCloud
user-data. The full build plan + per-app catalog lives in the MyAdmin repo: `apps.md`.

## Conventions

- First line is `#cloud-config`; the file is self-contained.
- `write_files` drops `/root/.<app>-bootstrap.env` (operator-editable vars) + `/root/<app>-bootstrap.sh`;
  `runcmd` runs the bootstrap once.
- Bootstrap: `set -euo pipefail`, tee to `/var/log/<app>-bootstrap.log`, random secrets
  (SIGPIPE-safe `... | head -c N || true`), harden (firewall + fail2ban + auto-updates + 2G swap),
  Certbot TLS that tolerates un-pointed DNS, run web apps as `www-data`, write
  `/root/<app>-credentials.txt` (mode 600).

## Base images

Default `ubuntu26.qcow2` (Ubuntu 26.04); fall back to `ubuntu24.qcow2` when a vendor repo has no
26.04 build. RHEL-only apps use `almalinux9.qcow2`; Debian-only apps use `debian12`/`debian13`.qcow2.

## Validate

```bash
python3 -c "import yaml; yaml.safe_load(open('wordpress.yaml'))"
cloud-init schema --config-file wordpress.yaml
```

`wordpress.yaml` and `openclaw.yaml` are the proven reference templates (exported from the live DB).
