#!/bin/bash
#
# ra-filter.sh -- stop upstream Router Advertisements from reaching guest ports,
#                 and stop guests from sending RAs of their own.
#
# WHY:
#   Upstream switches advertise the on-link /64 with the Autonomous flag set
#   (A=1) and the Managed flag clear (M=0). That tells every VPS "do not use
#   DHCPv6, invent your own address in this prefix" -- which is why guests come
#   up with random SLAAC addresses that our dhcpd6 never assigned, breaking
#   abuse attribution and letting a customer hop addresses at will.
#
#   Running radvd on the host with AdvAutonomous off does NOT fix this on its
#   own: br0 bridges the uplink to every vnetN, so the switch's RA is flooded
#   straight to the guests, and a guest honours EVERY RA it receives rather
#   than picking a winner. The upstream RA has to be dropped at the bridge.
#
#   The reverse direction matters too: one compromised VPS advertising itself
#   as a router would hijack every other guest on the bridge. That half is
#   worth having whether or not you run radvd.
#
# WHAT IT DOES (all idempotent, safe to re-run):
#   1. drops ICMPv6 type 134 (Router Advertisement) inbound on each bridge's
#      physical uplink port(s)
#   2. drops ICMPv6 type 134 inbound on each guest port (vnet*)
#   3. optionally stops the HOST itself honouring upstream RAs -- but only when
#      the host has a static default route to fall back on (see SAFETY below)
#
# SAFETY:
#   Step 3 is skipped unless `ip -6 route show default` reports a proto static
#   default route. Turning accept_ra off on a host whose only default route
#   came FROM an RA would drop that route and lock you out of the box. Force it
#   with FORCE_ACCEPT_RA_OFF=1 only if you know the host has static v6 routing.
#
# NOT PERSISTENT: ebtables rules are lost on reboot. Run this from your boot
#   path (or alongside run_buildebtables.sh) to reapply.
#
# USAGE:
#   ./ra-filter.sh            apply
#   ./ra-filter.sh status     show what is currently in place, change nothing
#   ./ra-filter.sh remove     take the rules back out
#
# ENV OVERRIDES:
#   BRIDGES="br0 br1"         bridges to operate on (default: autodetect)
#   GUEST_PREFIX="vnet"       guest interface name prefix (default: vnet)
#   FORCE_ACCEPT_RA_OFF=1     set accept_ra=0 even without a static default
#   DRY_RUN=1                 print the commands instead of running them

set -uo pipefail

GUEST_PREFIX="${GUEST_PREFIX:-vnet}"
ACTION="${1:-apply}"
DRY_RUN="${DRY_RUN:-0}"
FORCE_ACCEPT_RA_OFF="${FORCE_ACCEPT_RA_OFF:-0}"

RA_TYPE="router-advertisement"   # ICMPv6 type 134

log() { echo "[ra-filter] $*"; }
err() { echo "[ra-filter] ERROR: $*" >&2; }

run() {
	if [ "$DRY_RUN" = "1" ]; then
		echo "  + $*"
	else
		"$@"
	fi
}

if ! command -v ebtables >/dev/null 2>&1; then
	err "ebtables not found in PATH; install ebtables (or ebtables-legacy) first."
	exit 1
fi

# --- discover bridges -------------------------------------------------------
# Any interface with a bridge/ subdir in sysfs. Skip libvirt's own NAT bridges
# (virbr*), which have their own dnsmasq RA and are not customer-facing.
detect_bridges() {
	local b found=""
	for b in /sys/class/net/*/bridge; do
		[ -e "$b" ] || continue
		b="$(basename "$(dirname "$b")")"
		case "$b" in
			virbr*|docker*) continue ;;
		esac
		found="$found $b"
	done
	echo "$found"
}

BRIDGES="${BRIDGES:-$(detect_bridges)}"
BRIDGES="$(echo "$BRIDGES" | xargs || true)"

if [ -z "$BRIDGES" ]; then
	err "no bridges found; nothing to do. Set BRIDGES=... to override."
	exit 1
fi

# --- discover the uplink port(s) of a bridge --------------------------------
# A bridge port that is NOT a guest tap is the uplink to the switch. This covers
# plain NICs, bonds and vlan subinterfaces without hardcoding a name.
uplinks_for() {
	local bridge="$1" port
	for port in /sys/class/net/"$bridge"/brif/*; do
		[ -e "$port" ] || continue
		port="$(basename "$port")"
		case "$port" in
			"$GUEST_PREFIX"*|tap*|macvtap*) continue ;;
		esac
		echo "$port"
	done
}

guest_ports_for() {
	local bridge="$1" port
	for port in /sys/class/net/"$bridge"/brif/*; do
		[ -e "$port" ] || continue
		port="$(basename "$port")"
		case "$port" in
			"$GUEST_PREFIX"*|tap*|macvtap*) echo "$port" ;;
		esac
	done
}

# --- rule helpers -----------------------------------------------------------
# ebtables has no -C, so match against the listing instead. -i is only valid in
# INPUT/FORWARD; we use FORWARD so the host's own stack is untouched.
rule_exists() {
	local iface="$1"
	ebtables -L FORWARD 2>/dev/null | grep -qE -- "-i ${iface} .*${RA_TYPE}"
}

add_drop() {
	local iface="$1" label="$2"
	if rule_exists "$iface"; then
		log "already filtering RAs on ${iface} (${label})"
		return 0
	fi
	if run ebtables -A FORWARD -i "$iface" -p IPv6 --ip6-protocol ipv6-icmp \
		--ip6-icmp-type "$RA_TYPE" -j DROP; then
		log "dropping RAs inbound on ${iface} (${label})"
	else
		err "failed to add rule for ${iface}"
		return 1
	fi
}

del_drop() {
	local iface="$1"
	while rule_exists "$iface"; do
		run ebtables -D FORWARD -i "$iface" -p IPv6 --ip6-protocol ipv6-icmp \
			--ip6-icmp-type "$RA_TYPE" -j DROP || break
		log "removed RA filter on ${iface}"
		[ "$DRY_RUN" = "1" ] && break
	done
}

# --- host accept_ra ---------------------------------------------------------
host_has_static_default() {
	ip -6 route show default 2>/dev/null | grep -q "proto static"
}

set_accept_ra() {
	local value="$1" bridge
	if [ "$value" = "0" ] && [ "$FORCE_ACCEPT_RA_OFF" != "1" ] && ! host_has_static_default; then
		log "SKIP: host has no proto static IPv6 default route."
		log "      Turning accept_ra off would drop the host's only default route."
		log "      Add a static v6 default first, or re-run with FORCE_ACCEPT_RA_OFF=1."
		return 0
	fi
	for bridge in $BRIDGES; do
		run sysctl -qw "net.ipv6.conf.${bridge}.accept_ra=${value}"
		log "net.ipv6.conf.${bridge}.accept_ra=${value}"
	done
}

# --- actions ----------------------------------------------------------------
do_status() {
	local bridge port
	log "bridges: $BRIDGES"
	for bridge in $BRIDGES; do
		echo "  ${bridge}:"
		echo "    uplinks: $(uplinks_for "$bridge" | xargs || true)"
		echo "    guests:  $(guest_ports_for "$bridge" | xargs || true)"
		echo "    accept_ra: $(cat "/proc/sys/net/ipv6/conf/${bridge}/accept_ra" 2>/dev/null || echo '?')"
	done
	echo "  static default route: $(host_has_static_default && echo yes || echo no)"
	echo "  active RA drop rules:"
	ebtables -L FORWARD 2>/dev/null | grep -- "$RA_TYPE" | sed 's/^/    /' || echo "    (none)"
	local radvd_state
	radvd_state="$(systemctl is-active radvd 2>/dev/null || true)"
	[ -z "$radvd_state" ] && radvd_state="not installed"
	echo "  radvd: ${radvd_state}"
}

do_apply() {
	local bridge port
	for bridge in $BRIDGES; do
		log "bridge ${bridge}"
		for port in $(uplinks_for "$bridge"); do
			add_drop "$port" "uplink"
		done
		for port in $(guest_ports_for "$bridge"); do
			add_drop "$port" "guest"
		done
	done
	set_accept_ra 0
	log "done. Note: ebtables rules do NOT survive a reboot -- reapply from your boot path."
}

do_remove() {
	local bridge port
	for bridge in $BRIDGES; do
		for port in $(uplinks_for "$bridge") $(guest_ports_for "$bridge"); do
			del_drop "$port"
		done
	done
	set_accept_ra 1
	log "removed."
}

case "$ACTION" in
	apply)  do_apply ;;
	status) do_status ;;
	remove) do_remove ;;
	*)      err "unknown action '$ACTION' (expected: apply, status, remove)"; exit 1 ;;
esac
