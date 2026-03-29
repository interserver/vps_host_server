---
name: virsh-storage-manage
description: Manages libvirt storage pools and LVM/ZFS volumes following create_libvirt_storage_pools.sh and vps_kvm_lvmcreate.sh patterns. Handles virsh pool-define-as, lvcreate/zfs create, pool autostart, and vz pool detection logic. Use when asked to 'create storage pool', 'add LVM volume', 'setup ZFS', or work with /dev/vz/. Do NOT use for image installation, DHCP changes, or network configuration.
---
# virsh-storage-manage

## Critical

- All storage operations target the **`vz`** pool (VG or ZFS pool). Never hardcode a different pool name unless explicitly instructed.
- Always detect thin vs. thick provisioning before `lvcreate` — the check is `lvdisplay | grep 'Allocated pool'`. Non-empty = thin pool exists.
- Always check if a pool or volume already exists before creating it (idempotency).
- Never touch scripts in `unused/` — they are deprecated.
- Always export `PATH="/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin:/usr/local/bin:/usr/X11R6/bin:/root/bin"` at the top of any new shell script.

## Instructions

### 1 — Detect pool type for the `vz` pool

```bash
export pool="$(virsh pool-dumpxml vz 2>/dev/null | grep '<pool' | sed s#"^.*type='\([^']*\)'.*$"#"\1"#g)"
# Returns: "logical" (LVM), "zfs", or "" (not defined)
```

If `pool` is empty, bootstrap all pools first:
```bash
/root/cpaneldirect/create_libvirt_storage_pools.sh
export pool="$(virsh pool-dumpxml vz 2>/dev/null | grep '<pool' | sed s#"^.*type='\([^']*\)'.*$"#"\1"#g)"
```

Verify `$pool` is `"logical"` or `"zfs"` before proceeding.

### 2 — Register a new libvirt storage pool (`create_libvirt_storage_pools.sh` pattern)

```bash
function vaddpool() {
  if [ "$(virsh pool-list | grep -v -e '^$' -e '^-' -e '^ Name ' | awk '{ print $1 }' | grep "$2")" = "" ]; then
    echo "Adding $1 Pool $2"
    if [ "$1" = "logical" ]; then
      virsh pool-define-as "$2" $1 --source-name "$2" --target /dev/$2
    else
      virsh pool-define-as "$2" $1 --source-name "$2"
    fi
    virsh pool-start "$2"
    virsh pool-autostart "$2"
  else
    echo "Skipping already added $1 Pool $2"
  fi
}
```

To register all existing ZFS pools and LVM VGs:
```bash
for i in $(zpool list -H -p 2>/dev/null | cut -d"$(echo -e "\t")" -f1); do vaddpool zfs "$i"; done
for i in $(pvdisplay -c 2>/dev/null | cut -d: -f2);                       do vaddpool logical "$i"; done
```

Verify with `virsh pool-list --all` — pool must show `active` and `yes` for autostart.

### 3 — Create an LVM volume in `vz` VG (`vps_kvm_lvmcreate.sh` pattern)

```bash
name=$1   # e.g. vps1337
size=$2   # size in MB, or "all"

if [ "$(lvdisplay | grep 'Allocated pool')" = "" ]; then
  thin="no"
else
  thin="yes"
fi

if [ "$size" = "all" ]; then
  lvcreate -y -L $(echo "($(pvdisplay -c | grep :vz: | cut -d: -f8,10 | tr : "*"))-(1024*1024*4)" | bc)k -n${name} vz
elif [ "$(lvdisplay /dev/vz/$name | grep "LV Size.*"$(echo "$size / 1024" | bc -l | cut -d. -f1))" = "" ]; then
  if [ "$thin" = "yes" ]; then
    lvcreate -y -V${size} -T vz/thin -n${name}
  else
    lvcreate -y -L${size} -n${name} vz
  fi
else
  echo "already exists, skipping"
fi
```

Verify with `lvdisplay /dev/vz/${name}` — must return volume info.

### 4 — Create a ZFS dataset in `vz` pool

```bash
mkdir -p /vz/${name}
zfs create vz/${name}
while [ ! -e /vz/${name} ]; do sleep 1; done
```

For quota-capped volumes (e.g. templates):
```bash
zfs create vz/templates
zfs set quota=100G vz/templates
```

Verify with `zfs list vz/${name}`.

### 5 — Resolve the block device path after creation

```bash
if [ "$pool" = "zfs" ]; then
  device="$(virsh vol-list vz --details | grep " ${name}[/ ]" | awk '{ print $2 }')"  # e.g. /vz/vps1337/os.qcow2
else
  device="/dev/vz/${name}"
fi
```

### 6 — Remove a volume

```bash
if [ "$pool" = "zfs" ]; then
  virsh vol-delete --pool vz ${name}/os.qcow2 2>/dev/null
  virsh vol-delete --pool vz ${name}          2>/dev/null
  zfs list -t snapshot | grep "/${name}@" | cut -d" " -f1 | xargs -r -n1 zfs destroy -v
  zfs destroy vz/${name}
  [ -e /vz/${name} ] && rmdir /vz/${name}
else
  lvremove -f /dev/vz/${name}
fi
```

## Examples

**User says:** "Set up a 50 GB LVM volume for vps42"

**Actions taken:**
1. Detect pool type: `pool="logical"`
2. Check thin: `lvdisplay | grep 'Allocated pool'` → empty → `thin="no"`
3. Check existence: `lvdisplay /dev/vz/vps42` → empty → proceed
4. Create: `lvcreate -y -L51200 -nvps42 vz`
5. Confirm device: `device="/dev/vz/vps42"`

**Result:** `/dev/vz/vps42` exists, 50 GB thick LVM volume in `vz` VG.

---

**User says:** "Bootstrap libvirt pools on a fresh KVM host"

**Actions taken:**
1. Run `./create_libvirt_storage_pools.sh`
2. Script calls `vaddpool logical vz` and/or `vaddpool zfs vz` depending on host config
3. `virsh pool-autostart vz` is called inside `vaddpool`

**Result:** `virsh pool-list --all` shows `vz` as active with autostart.

## Common Issues

- **`virsh pool-define-as` fails with "storage pool already exists"**: Pool is defined but stopped. Run `virsh pool-start vz` then `virsh pool-autostart vz`. Do not redefine.
- **`lvcreate` fails with "Volume group "vz" not found"**: The `vz` VG was not created. Verify with `vgs`; if missing, check that physical volumes are initialized with `pvcreate` and VG created with `vgcreate vz /dev/sdX`.
- **`lvcreate` fails with "Insufficient free space"**: Use `vgdisplay vz` to check free extents. For thin pools, check `lvdisplay vz/thin` pool data%.
- **`zfs create` fails with "no such pool"**: Run `zpool import vz` or verify with `zpool status`. If pool is degraded, do not proceed without user confirmation.
- **`pool` variable is empty after `pool-dumpxml`**: `vz` pool not registered. Run `./create_libvirt_storage_pools.sh` (located at `/root/cpaneldirect/create_libvirt_storage_pools.sh` on hosts) then re-check.
- **Thin `lvcreate` fails with "pool vz/thin not found"**: Host has LVM but no thin pool configured. Set `thin="no"` and use thick provisioning: `lvcreate -y -L${size} -n${name} vz`.