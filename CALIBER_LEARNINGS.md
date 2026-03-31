# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[gotcha]** In golden-images Docker builds, `/etc/resolv.conf` is bind-mounted read-only by the Docker daemon. Neither truncating (`: >`), removing (`rm -f`), nor direct writing works — all fail with "Read-only file system". The DNS fix in `render_dockerfile.sh` and `lib/dockerfile.sh` must handle this gracefully (e.g., skip if read-only, use `cp /dev/null` with error suppression, or configure DNS via Docker daemon `--dns` flags instead of in-container file writes).
- **[pattern]** When fixing Dockerfile build issues in `golden-images/`, changes must be applied to BOTH `golden-images/render_dockerfile.sh` (standalone renderer) AND `golden-images/lib/dockerfile.sh` (library used by `golden-build`) — they generate Dockerfiles independently with duplicated logic.
- **[gotcha]** The `golden-images/docker-ssh-builder/` directory contains older builder scripts (`build_all_images.sh`, `docker_ssh_builder_v2.sh`, `docker_ssh_builder_v3.sh`) that also reference `/etc/resolv.conf`. Fixes to the DNS handling pattern must be applied there too if those scripts are still in use.
