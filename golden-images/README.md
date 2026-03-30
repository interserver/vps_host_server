# Golden image builder

Ready-to-run helper scripts for building Docker golden images with:

- OpenSSH installed
- sshd started on container startup
- root password set at build time
- SSH smoke test after build

## Files

- `images.matrix` - list of base images to build
- `render_dockerfile.sh` - generates Dockerfile + entrypoint for one image
- `verify_image.sh` - runs SSH verification against a built image
- `build_all.sh` - parses matrix, builds all images, verifies, optionally pushes

## Quick start

```bash
cd scripts/golden-images
export ROOT_PASSWORD='ChangeMe123!'
export REGISTRY_PREFIX='provirted'
export PARALLELISM=4
./build_all.sh ./images.matrix
```

## Optional environment variables

- `ROOT_PASSWORD` (default: `ChangeMeNow!`)
- `REGISTRY_PREFIX` (default: `provirted`)
- `PARALLELISM` (default: `4`)
- `PUSH_IMAGES=1` to push after successful build
- `VERIFY_IMAGES=0` to skip verification
- `WORK_DIR=/path/to/build-workdir`

## Input format examples

### Format A: one image per line

```text
ubuntu:24.04
debian:12
```

### Format B: one image + explicit tag

```text
ubuntu:24.04,registry.example.com/provirted/ubuntu-24.04-ssh
```

### Format C: grouped family + versions (compatible with your pasted list style)

```text
ubuntu: 22.04,24.04,24.10,
alpine: 3.20,3.21,
```

This expands into one build per version.

## Notes

- `cirros`, `sl`, and `busybox` are skipped by default in `build_all.sh` because they are not standard package-manager targets for this SSH bootstrap approach.
- For these skipped families, create dedicated custom Dockerfiles or prebuilt artifacts.
