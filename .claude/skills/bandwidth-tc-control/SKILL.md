---
name: bandwidth-tc-control
description: Applies per-VPS bandwidth limits using tc/HTB and ebtables following patterns in `tclimit`, `limitbw`, and `run_buildebtables.sh`. Handles vnet interface detection, HTB qdiscs, /tools/bandwidth/{id}/ flag files, and ebtables FORWARD rules. Use when asked to 'set bandwidth limit', 'apply tc rules', 'update ebtables', or run `limitbw`. Do NOT use for OpenVZ/venet traffic shaping (see `vzopenvztc.sh` which uses CBQ, not HTB).
---
# bandwidth-tc-control

## Critical

- **Never apply KVM `tclimit` HTB rules to OpenVZ VMs** — OpenVZ uses CBQ via `vzopenvztc.sh`.
- **Always delete existing qdiscs before adding new ones** — duplicate qdiscs cause `RTNETLINK: File exists` errors.
- **Check `/nobwlimit`** — if this file exists on the host, exit without applying any tc rules.
- **Check `_notclimit`** — if `${base}/_notclimit` exists, skip tclimit and instead clean up all existing vnet qdiscs: `for i in $(ifconfig | grep ^vnet | cut -d: -f1); do /sbin/tc qdisc del dev $i root; done`
- **Skip QuickServers** — `limitbw` checks `crontab -l | grep qs_cron`; if found, exit. Do not apply KVM tc rules to QS hosts.

## Instructions

1. **Determine the bandwidth tier for a VPS.**  
   Check `/tools/bandwidth/<id>/` for a numeric flag file. Supported tiers: `5`, `10`, `15`, `20`, `25`, `30`, `40`, `50`, `60`, `70`, `80` (mbit). If `/tools/bandwidth/<id>/skip` exists, skip that VPS entirely. If no flag file exists, default is `100mbit`.
   ```sh
   ls /tools/bandwidth/<id>/
   ```
   To set a tier: `mkdir -p /tools/bandwidth/<id> && touch /tools/bandwidth/<id>/20`  
   Verify the correct file exists before proceeding.

2. **Detect the vnet interface for a running KVM guest.**  
   ```sh
   virsh list | grep <hostname>   # must show 'running'
   vnet=$(virsh dumpxml <hostname> | grep 'vnet' | cut -d\' -f2)
   ```
   Verify `$vnet` is non-empty (e.g. `vnet0`, `vnet3`). If the VM is not running, `tclimit` must not be called.

3. **Detect the physical outbound interface (`$OF`).**  
   `tclimit` auto-detects based on hostname and distro; the logic in order:
   - Hardcoded for `kvm1.trouble-free.net`, `kvm2.interserver.net`, `kvm50.interserver.net` → `eth1`
   - Debian: `ip route | grep default | sed ...` (resolves bridge `br0` to its member via `brctl show`)
   - RHEL: checks `/sys/class/net/enp9s0f0`, `enp11s0f0`, else `eth0`
   - Default: `eth0`  
   This is handled automatically by `tclimit`; do not override unless adding a new host.

4. **Apply tc HTB rules for a single VPS.**  
   Call `tclimit` with: `./tclimit <ip> <vnet_iface> <vps_id>`  
   Example: `./tclimit 192.168.1.50 vnet3 vps50`  
   Internally this runs:
   ```sh
   /sbin/tc qdisc del dev $vnet root
   /sbin/tc qdisc del dev $eth root
   /sbin/tc qdisc add dev $vnet root handle 1:0 htb default 30 r2q 15
   /sbin/tc qdisc add dev $eth  root handle 1:0 htb default 30 r2q 15
   /sbin/tc class add dev $vnet parent 1:0 classid 1:1 htb rate <DNLD>
   /sbin/tc class add dev $eth  parent 1:0 classid 1:1 htb rate <UPLD>
   /sbin/tc filter add dev $vnet protocol ip parent 1:0 prio 1 u32 match ip dst <ip>/32 flowid 1:1
   /sbin/tc filter add dev $eth  protocol ip parent 1:0 prio 1 u32 match ip src <ip>/32 flowid 1:1
   ```
   Verify with: `/sbin/tc -s qdisc ls dev $vnet`

5. **Apply limits for all running VMs (bulk).**  
   Run `./limitbw` from the project root. It reads MACs and IPs from `/etc/dhcp/dhcpd.vps` (or `/etc/dhcpd.vps`), checks `virsh list` for running state, then calls `./tclimit <ip> <vnet> <host>` for each.  
   Verify output shows `Limiting <ip>` for each running VM; `not running` lines are expected for stopped VMs.

6. **Apply ebtables FORWARD rules.**  
   Run `./run_buildebtables.sh` from the project root. It executes `./buildebtablesrules | bash` with stderr to `/tmp/buildeb.err` and stdout summary to `/tmp/buildeb.out`.  
   Verify: check that no errors appear in `/tmp/buildeb.err` after the run.

## Examples

**User says:** "Set vps99 to 20mbit bandwidth limit"  
**Actions taken:**
```sh
mkdir -p /tools/bandwidth/vps99
# Remove any conflicting tier files first
rm -f /tools/bandwidth/vps99/{5,10,15,25,30,40,50,60,70,80,100,skip}
touch /tools/bandwidth/vps99/20

# Get vps99's current IP and vnet
ip=$(grep 'vps99' /etc/dhcp/dhcpd.vps | awk '{ print $8 }' | cut -d\; -f1)
vnet=$(virsh dumpxml vps99 | grep 'vnet' | cut -d\' -f2)

./tclimit $ip $vnet vps99
/sbin/tc -s qdisc ls dev $vnet
```
**Result:** HTB qdisc on `$vnet` and outbound interface, both capped at `20mbit`.

**User says:** "Re-apply all bandwidth limits after reboot"  
```sh
./limitbw
./run_buildebtables.sh
```

## Common Issues

- **`RTNETLINK answers: No such file or directory` on `tc qdisc del`** — the qdisc didn't exist yet. This is harmless; `tclimit` always deletes before adding.
- **`RTNETLINK answers: File exists` on `tc qdisc add`** — the delete failed or was skipped. Run `tc qdisc del dev <vnet> root` manually, then retry.
- **`vnet` is empty from `virsh dumpxml`** — the VM is using a bridge/macvtap, not a vnet tap. Confirm with `virsh domiflist <host>`. If the interface type is `bridge`, the guest is not using vnet and `tclimit` cannot be applied directly.
- **`buildebtablesrules: command not found`** — the compiled binary is missing. It should be in the project root alongside `run_buildebtables.sh`. Check `ls ./buildebtablesrules`.
- **`limitbw` exits immediately with `#skipping for qs`** — the crontab has a `qs_cron` entry, meaning this is a QuickServers host. KVM `tclimit` rules do not apply; use `vzopenvztc.sh` patterns instead.
- **Limits not taking effect despite no errors** — check if `/nobwlimit` exists (`ls /nobwlimit`). If present, `tclimit` exits before applying rules. Remove it if bandwidth limiting should be active.