---
name: kvm-vps-create
description: Provisions a new KVM VPS: LVM or ZFS volume creation via vps_kvm_lvmcreate.sh, libvirt XML config from windows.xml, DHCP entry in dhcpd.vps, VNC setup via provirted.phar, and ebtables rebuild via run_buildebtables.sh. Use when asked to 'create a VPS', 'provision KVM', 'add a new VM', or modify install scripts. Do NOT use for LXD, OpenVZ, or Virtuozzo provisioning.
---
# kvm-vps-create

## Critical

- VPS name MUST match pattern `(qs|windows|linux|vps)[0-9]+` — e.g. `windows1337`, `linux500`, `vps42`. Never invent other prefixes.
- Numeric ID is extracted with: `id=$(echo ${vps}|sed s#"^\(qs\|windows\|linux\|vps\)\([0-9]*\)$"#"\2"#g)`
- Detect storage backend BEFORE creating volumes: `virsh pool-dumpxml vz | grep -q "<type>zfs"` → ZFS path `/vz/${vps}/os.qcow2`; otherwise LVM path `/dev/vz/${vps}`.
- DHCP file is `/etc/dhcp/dhcpd.vps`; fall back to `/etc/dhcpd.vps` if the first does not exist.
- Base dir for all scripts: `base="$(readlink -f "$(dirname "$0")")"`
- Never restore or reference anything under `unused/`.

## Instructions

1. **Set variables**
   ```bash
   base="$(readlink -f "$(dirname "$0")")"
   vps="$1"          # e.g. windows1337
   ip="$2"           # VPS IP address
   root="$3"         # Root password
   size="$4"         # Disk size in MB
   memory="$5"       # Memory in KiB (e.g. 2097152)
   vcpu="$6"         # vCPU count (1–8)
   max_cpu=8
   max_memory=16384000
   id=$(echo ${vps}|sed s#"^\(qs\|windows\|linux\|vps\)\([0-9]*\)$"#"\2"#g)
   ```
   Verify `$id` is numeric and non-empty before continuing.

2. **Generate MAC address**
   ```bash
   prefix="00:16:3E"   # Use 00:0C:29 for qs* VPS only
   s="$(printf "%06s" $(echo "obase=16; $id"|bc)|sed s#" "#"0"#g)"
   mac="${prefix}:${s:0:2}:${s:2:2}:${s:4:2}"
   ```
   Verify `$mac` matches `^([0-9A-F]{2}:){5}[0-9A-F]{2}$` before continuing.

3. **Detect storage pool type**
   ```bash
   pool="$(virsh pool-dumpxml vz 2>/dev/null | grep '<type>' | sed 's/.*<type>\(.*\)<\/type>.*/\1/')" 
   # pool = "zfs" or "logical"
   ```
   If `virsh pool-dumpxml vz` fails, run `./create_libvirt_storage_pools.sh` first.

4. **Create storage volume**

   *LVM (pool = logical):*
   ```bash
   ${base}/vps_kvm_lvmcreate.sh ${vps} ${size}
   device="/dev/vz/${vps}"
   ```

   *ZFS (pool = zfs):*
   ```bash
   zfs create vz/${vps}
   device="/vz/${vps}/os.qcow2"
   qemu-img create -f qcow2 -o preallocation=metadata $device ${size}M
   ```
   Verify `[ -e "$device" ]` before continuing.

5. **Build libvirt XML from template**
   ```bash
   cp ${base}/windows.xml /tmp/${vps}.xml
   sed -i s#"windows"#"${vps}"#g /tmp/${vps}.xml
   sed -i s#"<mac address='.*'"#"<mac address='$mac'"#g /tmp/${vps}.xml
   sed -i s#"<parameter name='IP' value.*/>"#"<parameter name='IP' value='$ip'/>"#g /tmp/${vps}.xml
   sed -i s#"<source dev='/dev/vz/windows'"#"<source dev='$device'"#g /tmp/${vps}.xml
   sed -i s#"<\(vcpu.*\)>.*</vcpu>"#"<vcpu placement='static' current='$vcpu'>$max_cpu</vcpu>"#g /tmp/${vps}.xml
   sed -i s#"<memory.*memory>"#"<memory unit='KiB'>$memory</memory>"#g /tmp/${vps}.xml
   sed -i s#"<currentMemory.*currentMemory>"#"<currentMemory unit='KiB'>$memory</currentMemory>"#g /tmp/${vps}.xml
   ```
   Verify `virsh define /tmp/${vps}.xml` exits 0 before continuing.

6. **Register VM and set resource limits**
   ```bash
   virsh define /tmp/${vps}.xml
   virsh autostart ${vps}
   virsh schedinfo ${vps} --set cpu_shares=$((vcpu * 1024))
   ```

7. **Update DHCP**
   ```bash
   DHCPVPS="/etc/dhcp/dhcpd.vps"
   [ ! -f "$DHCPVPS" ] && DHCPVPS="/etc/dhcpd.vps"
   cp $DHCPVPS ${DHCPVPS}.backup
   grep -v -e "host ${vps} " -e "fixed-address $ip;" ${DHCPVPS}.backup > $DHCPVPS
   echo "host ${vps} { hardware ethernet $mac; fixed-address $ip; }" >> $DHCPVPS
   ```
   Verify the new entry exists: `grep -q "host ${vps}" $DHCPVPS`

8. **Rebuild ebtables firewall**
   ```bash
   ${base}/run_buildebtables.sh
   ```
   Check `/tmp/buildeb.err` is empty; if not, inspect and re-run.

9. **Set up VNC**
   ```bash
   vnc="$(grep "^$id:" ${base}/vps.vncmap | cut -d: -f2)"
   ${base}/provirted.phar vnc setup $vps $vnc
   ```
   Verify `virsh vncdisplay $vps` returns a display number.

10. **Start VM**
    ```bash
    virsh start ${vps}
    virsh dominfo ${vps}
    ```

## Examples

**User says:** "Create a KVM VPS named windows42 with IP 192.168.1.42, 2 GB RAM, 1 vCPU, 25600 MB disk."

**Actions taken:**
```bash
vps=windows42; id=42; ip=192.168.1.42; memory=2097152; vcpu=1; size=25600
mac="00:16:3E:00:00:2A"           # id 42 = 0x00002A
# pool=logical → LVM path
./vps_kvm_lvmcreate.sh windows42 25600  # creates /dev/vz/windows42
cp windows.xml /tmp/windows42.xml && sed ...  # patch name, mac, ip, memory, vcpu
virsh define /tmp/windows42.xml
grep -v ... /etc/dhcp/dhcpd.vps.backup > /etc/dhcp/dhcpd.vps
echo "host windows42 { hardware ethernet 00:16:3E:00:00:2A; fixed-address 192.168.1.42; }" >> /etc/dhcp/dhcpd.vps
./run_buildebtables.sh
./provirted.phar vnc setup windows42 <vnc_ip_from_vps.vncmap>
virsh start windows42
```

**Result:** `virsh dominfo windows42` shows `State: running`.

## Common Issues

- **`lvcreate: Volume group "vz" not found`** — Run `./create_libvirt_storage_pools.sh` to define the `vz` pool, then retry `vps_kvm_lvmcreate.sh`.
- **`virsh define` fails with `domain already exists`** — Run `virsh undefine ${vps} && virsh managedsave-remove ${vps}` before redefining.
- **`sed` leaves `windows` in XML name field** — Confirm the template `windows.xml` uses bare `<name>windows</name>`; the sed pattern is literal string replacement, not regex.
- **VNC not reachable after setup** — Confirm `$id:` entry exists in `vps.vncmap` (`grep "^${id}:" vps.vncmap`); if missing, add it then re-run `./vps_refresh_vnc.sh ${vps}`.
- **DHCP not assigning IP** — Verify single-line entry format: `host ${vps} { hardware ethernet $mac; fixed-address $ip; }` — no line breaks. Restart dhcpd after edit.
- **ebtables errors in `/tmp/buildeb.err`** — Verify `buildebtablesrules` is executable: `ls -l ${base}/buildebtablesrules`. Check `vps.vncmap` and DHCP file are consistent with running VMs.
- **ZFS volume not found after `zfs create`** — Confirm pool name with `zpool list`; the pool must be named `vz` for paths like `/vz/${vps}` to work.