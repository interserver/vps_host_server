# ROLLOUT_PLAN — staged per-host cutover to the new WS host agent (REFERENCE ONLY, drafted 2026-07-12)

> ## ⚠️ STATUS: reference document, NOT an active roadmap
>
> Per the **owner clarification dated 2026-07-07** (recorded in `BASELINE.md`
> §13.0), the WS host agent built in this repo is **not on the production
> critical path**. The path real client traffic actually depends on today is
> the crontabbed `vps_cron.sh` / `qs_cron.sh` → `provirted.phar` + HTTP
> `queue.php:55151` fallback — confirmed byte-unchanged and structurally
> uncoupled from this agent by the step 3.7 boundary audit (`BASELINE.md`
> §13.2–§13.6, verified independently ×2 + Test stage).
>
> **Nobody is currently planning to execute the waves below on any timeline.**
> This document exists so that *if* a cutover is ever wanted, the staging,
> monitoring, and rollback thinking is already done against the real
> mechanisms that exist on disk — rather than being improvised under pressure.
> A future reader must not mistake the wave numbering below for a schedule or
> a commitment. There is no deadline, no owner, and no obligation to ever run
> Wave 1. Indefinite non-cutover (§8) is an explicitly acceptable end state.

Everything in this plan builds on mechanisms that **already exist**:

- the **`.enable_workerman` flag file** gate in `vps_cron.sh`/`qs_cron.sh`
  (pre-existing, 2018-era — `BASELINE.md` §13.3). This is THE per-host cutover
  switch. This plan does not invent a parallel one.
- the hub's **Flag A** (`FeatureFlags::useNewHandling()`, `WS_NEW_HANDLING`)
  dormancy gate from Phase 2 (hub repo `docs/FEATURE_FLAGS.md`). This plan
  references it and does not re-decide or duplicate its design.
- the agent's **`TokenStore::hasToken()`** dual-running gate (`BASELINE.md`
  §11.2): no token file → byte-identical legacy agent; token present → v1.

Where something referenced below does **not** exist yet (a dashboard, a
metric, a functional gap), this plan says so explicitly rather than implying
it is ready.

---

## 1. The switch model — what actually gets flipped, per host

Three independent gates must all be in the "on" position for a host to be
fully cut over. They live on two machines and fail safe individually:

| # | Gate | Where | Off state | On state |
|---|---|---|---|---|
| 1 | Flag A (`WS_NEW_HANDLING`) | hub (`datacentered`), GlobalData-backed | v1 envelope router fully dormant; hub speaks legacy only | hub answers v1 ops for authed v1 connections |
| 2 | Agent token file (`/etc/datacentered/agent_token`, 0600) | host, via `TokenStore` | agent is byte-identical to the pre-3.5 legacy agent (`BASELINE.md` §11.2) | agent sends `auth.hello` first-frame, runs the v1 lane |
| 3 | `.enable_workerman` flag file | host, `/home/sites/vps_host_server/` | cron runs the legacy `provirted.phar` + HTTP path unconditionally | cron ensures the WS agent is running and **skips the entire legacy block** |

Gate 3 is the consequential one for production: reading the actual script
logic (`vps_cron.sh:24-35`, `qs_cron.sh:25-36`), when `.enable_workerman`
exists the cron sets `old_cron=0`, (re)starts `workerman/start.php` if it is
not running, and — if the start succeeded — **skips every legacy action**:
`provirted.phar cron cpu-usage / host-info / bw-info / vps-info`, the
`get_new_vps`/`get_new_qs` fetch, the `action=map` fetch, and the `get_queue`
fetch. It is **all-or-nothing per host**. There is no partial mode where the
cron runs half its legacy duties alongside the agent.

The built-in safety net, also pre-existing: if the agent fails to start twice
(status check empty after `update.sh` + `restart -d` + 2s), the script emails
`"$HOSTNAME cannot start workerman"` to detain@interserver.net and sets
`old_cron=1` — **the legacy block runs after all, that same minute**. This
fail-back covers "agent won't start"; it does NOT cover "agent started but is
misbehaving" (see §7).

---

## 2. Prerequisites before ANY wave could begin

None of these are met as a package today. Each is a hard blocker for Wave 1
(Dev), not something to discover mid-canary.

### 2.1 Hub side (datacentered — referenced, not re-designed here)

- **Flag A enabled** on the hub (`FeatureFlags::useNewHandling()` per the hub's
  `docs/FEATURE_FLAGS.md`). Flag A is currently the Phase-2 dormancy default
  (OFF). Note Flag A is hub-global, not per-host — enabling it activates the
  v1 router for any host that presents a token, but hosts without tokens are
  unaffected (they never send v1 frames), so per-host granularity comes from
  gates 2 and 3, not from Flag A. (The underlying `FeatureFlags` mechanism
  does support per-host overrides via `ws_new_handling_host_<id>`, but the
  current v1 router gate doesn't pass a hostId, so today it functions as
  hub-global.)
- **Token schema migration applied**: `migrations/2026_07_phase2_token_auth.sql`
  (hub repo) adding the `*_token_*` columns to `vps_masters`/`qs_masters` —
  documented as behavior-neutral, operator-applied manually (PROTOCOL_V1 §6,
  step 2.2 note).
- **Hub-side `config.token` push implemented.** As of `BASELINE.md` §11.5 the
  agent *accepts* `config.token`, but "the hub has not implemented the
  `config.token` push yet." Until it does, token provisioning is manual
  (§2.2). Not a blocker for Dev if tokens are hand-placed, but a blocker for
  any fleet-scale wave — hand-editing 0600 files on hundreds of hosts is not
  a rotation story.

### 2.2 Host side (this repo)

- **`composer install` on the target host.** `vendor/` is not committed to
  git; at baseline time (step 3.1) no `vendor/` was installed (`BASELINE.md`
  §1) and target hosts may not have one — `composer.lock` is the source of
  truth (PHP >=8.2, Workerman v5.2.2) and `update.sh` runs
  `composer install --no-dev`. The cron's agent-start path runs
  `workerman/update.sh` then `start.php restart` — verify `update.sh` produces
  a runnable install (deps present, PHP 8.2+ on the host) *before* creating
  the flag, or the very first flip will just exercise the fail-back email.
- **Token file provisioned** per host: `/etc/datacentered/agent_token`, mode
  0600, non-empty (`TokenStore::hasToken()`, `BASELINE.md` §11.2 — empty/
  whitespace/unreadable all count as no-token → legacy). Bootstrap options:
  hub `config.token` push over the legacy-authenticated link (§11.5, once the
  hub side exists) or manual placement. A host with `.enable_workerman` set
  but **no token** would run the agent in pure-legacy WS mode (old `login` by
  source IP) — a valid intermediate, but not the v1 target state.
- **Matching hub registry row** (`vps_masters`/`qs_masters`) with the issued
  token hash and correct source IP — the hub validates token AND source IP
  (PROTOCOL_V1 §3).

### 2.3 Known functional gaps that MUST be closed before gate 3 is ever flipped on a production host

These are documented, pre-existing gaps in `BASELINE.md` — flipping
`.enable_workerman` today would silently drop duties the legacy cron
currently performs:

1. **Queue processing is dormant in the agent.** The `queue.*` senders
   (`V1Client::queuePull`/`queueProvision`) are wired but
   `Agent::setupTimers()` **never registers `vps_queue_timer`** (`BASELINE.md`
   §11.6, pre-existing gap). With the legacy cron block skipped, **nothing on
   the host would pull `get_queue`/`get_new_vps`** — provisioning and queued
   jobs for that host would stop. This is the single most important blocker:
   it must be fixed and live-verified (a queued job flows end-to-end over WS)
   before any production flip.
2. **`telemetry.cpu` never sends** — `src/Tasks/vps_get_cpu.php` returns an
   undefined `$data` (always null); the v1 branch detects this and skips
   (`BASELINE.md` §11.7 bug 1). Legacy cron covers cpu-usage via
   `provirted.phar cron cpu-usage`. Fix the task (or accept the metric gap
   knowingly and in writing) before cutover.
3. **`queue.*`, `cmd.stdin`, `agent.update`, `telemetry.sysinfo` are
   structural-only** — never live-exercised against a real hub (`BASELINE.md`
   §11.6). The Dev stage (§3) exists largely to burn this list down.
4. **`cron.cpu_usage` shared state file** (`BASELINE.md` §13.5): if agent and
   legacy cron ever run simultaneously on the same host, both write it. The
   flag gate makes cron either/or, so this only bites in the deliberate
   side-by-side Dev configuration (§3) and during a messy rollback (§7.3) —
   but it must be on the checklist.

### 2.4 Observability floor (be honest: most of this does not exist yet)

What exists today:

- **Hub logs**: `Worker::$stdoutFile` → `/home/my/logs/billingd.log`.
- **Agent logs**: Workerman stdout on each host (`cron.output` captures the
  start attempts; the agent's own `[Reconnect]` lines per `BASELINE.md` §9.7).
- **Hub InfluxDB v2**: wired for bandwidth (`Tasks/bandwidth.php` /
  `memcached_queue_task`) and HyperV SOAP call metrics — **not** for v1
  protocol error rates, auth failures, reconnect counts, or PTY session
  counts. No such series or dashboard exists.

What would need to be **built** before Canary (Wave 2) — not before Dev:

- A minimal v1-health signal on the hub: counts of `auth_failed`/`bad_request`/
  `unknown_op`/`not_implemented` replies and v1 connections per host (the
  Influx v2 client and the `$influx_v2_database` global already exist hub-side;
  this is new instrumentation, not new infrastructure).
- A per-host "agent connected + authed + last telemetry age" view, even if it
  is just a query against `$global->hosts` / GlobalData rather than a real
  dashboard.

Until those exist, "monitoring the canary" means grep on two log files —
workable for 1–3 hosts, not for a fleet.

---

## 3. Wave 1 — Dev (one non-production host)

**Scope:** exactly one non-production/test host. Goal is to light up the
`BASELINE.md` §11.6/§12.8 "structural but not live-exercised" list against
the real hub, not to prove scale.

**The side-by-side nuance, stated honestly.** `.enable_workerman` is a legacy
all-or-nothing switch (§1): with the flag set, the legacy cron block does not
run, so the flag itself cannot give you "both paths at once." True
side-by-side on the Dev host therefore means **not** setting the flag, and
instead starting the agent manually (`workerman/start.php start -d`) while
the legacy cron continues untouched. This is safe precisely because step 3.7
proved the two paths structurally uncoupled — disjoint ports (`55552`/`55553`
local vs hub `7271`/`7272` WS vs `55151` HTTP), disjoint state files
(`BASELINE.md` §13.6) — with the **one** documented exception: the
`cron.cpu_usage` shared file (§13.5). On the Dev host either accept the
harmless-on-a-test-box collision, or point the agent's
`src/Config/settings.php` cpu-usage path elsewhere for the duration.

Dev checklist:

1. Hub Flag A ON; token row provisioned; token file placed; agent started
   manually alongside legacy cron.
2. Verify the v1 handshake: `auth.hello` → `auth.welcome`, timers armed from
   the welcome payload.
3. Exercise everything on the not-yet-live list: `cmd.stdin` interactive
   runs, `agent.update`, `telemetry.sysinfo`, the full hub-relayed
   `pty.open`→`pty.data`→`pty.resize`→`pty.close` loop over a real WSS link
   (`BASELINE.md` §12.8 — blocked today on the hub's conservative-denied pty
   scope gate; enabling that hub-side is part of this wave's work).
4. Fix and verify §2.3 gaps 1–2 (queue timer, `vps_get_cpu`), then flip
   `.enable_workerman` **on the Dev host only** and confirm the agent alone
   carries the full legacy duty set for at least several days: queue pulls
   produce and execute jobs, maps stay byte-identical, bandwidth/host
   telemetry keeps flowing, ReconnectManager survives a deliberate hub
   restart (expect `[Reconnect]` backoff lines, 2→4→8→…→60s ±20% jitter, and
   auto re-auth — `BASELINE.md` §9).
5. Exercise the fail-back deliberately once: break the agent start, confirm
   the "cannot start workerman" mail arrives and the legacy block runs that
   minute.

**Exit criteria:** every op family live-verified; queue jobs flow over WS;
one full flag-on week with no manual intervention; rollback rehearsed (§7).

---

## 4. Wave 2 — Canary (<5% of production hosts)

**Scope:** a handful of deliberately low-risk production hosts (few VPSes,
tolerant customers, one virt type at a time — start with whichever type Dev
used). `.enable_workerman` set for real; §2 prerequisites all met, including
the §2.4 observability additions (this wave is where log-grepping stops
scaling).

**What to actually watch** (per host, for at least 1–2 weeks):

| Signal | Source | Bad looks like |
|---|---|---|
| Reconnect behavior | agent log `[Reconnect]` lines; hub connect/auth counts | reconnect storm: repeated short-interval reconnects (backoff never resetting means `confirmConnected()` never fires — link up but no real frames, `BASELINE.md` §9.2) |
| Agent process health | `cron.output` (the cron restarts a dead agent every minute); "cannot start workerman" emails | crash-looping: the cron's own restart-if-not-running check firing repeatedly is itself the crash-loop detector — recurring `update.sh`+restart lines every minute |
| Queue processing | `queue_log` rows on the hub for canary hosts; provisioning completion (`vps.finished` / legacy `finished`) | rows sitting in queued state, provisioning stalls, `install_progress` silence |
| v1 protocol errors | new §2.4 hub instrumentation (`bad_request`/`unknown_op`/`auth_failed` counts) | any sustained nonzero rate for a canary host |
| Telemetry continuity | hub Influx bandwidth series per VPS (already exists); `vps_masters` server-info freshness | flatlined bandwidth or stale host info vs pre-cutover baseline |
| Map integrity | `vps.mainips`/`ipmap`/`vncmap`/`slicemap` on-host mtimes + spot `cmp` vs hub output | map files diverging (would break provirted VNC/ebtables — E1 invariant, PROTOCOL_V1 §2.6) |
| PTY health | agent-side `PTYPool` count (60s reaper log); hub pty audit log (PROTOCOL_V1 §5) | pty count growing without bound; note `code:-1` on `pty.close` is a **known cosmetic** quirk, not a failure (`BASELINE.md` §12.8) |

**Exit criteria to proceed to Wave 3:** zero rollbacks; zero provisioning
failures attributable to the agent; reconnects only correlated with actual
hub restarts/network events, always self-healing; error-rate signal flat.

---

## 5. Wave 3 — 50%

**Scope:** half the fleet, mixed virt types, including some high-density
hosts. Only reachable if Wave 2 exited clean AND token distribution is
automated (`config.token` push, §2.1) — manual token placement at 50% scale
is an error factory.

Additional criteria beyond §4's (which keep applying):

- Rollout in batches (e.g. 10–20 hosts per batch, one batch per day or
  slower), watching the §4 table between batches. The flag is per-host, so
  batching is trivially cheap — use it.
- Watch the **hub** side for aggregate effects that no single canary could
  show: BusinessWorker/Gateway load on `7271`/`7272` with many persistent v1
  connections, GlobalData contention, and the thundering-herd behavior after
  a deliberate hub restart at fleet scale (the ±20% jitter in
  `ReconnectManager::nextDelay()` exists exactly for this — `BASELINE.md`
  §9.1 — but it has never been observed at real fleet size).
- At least one full hub deploy/restart cycle during this wave, on purpose,
  to observe mass reconnection.

---

## 6. Wave 4 — 100%

**Scope:** all remaining hosts, same batching discipline.

**What "done" means for this plan:** every host has `.enable_workerman` set,
a valid token, an authed v1 connection, and the legacy cron block no longer
executes anywhere. The §4 monitoring table is green fleet-wide through at
least one hub restart and one agent `agent.update` cycle.

**Explicitly OUT of scope — legacy retirement.** This plan only concerns
turning ON the new agent per-host. It does **not** cover:

- removing `provirted.phar`, the cron scripts, or the HTTP `queue.php:55151`
  path (the cron scripts remain in place as the §7 rollback vehicle
  indefinitely — deleting them would delete the rollback);
- retiring the hub's legacy `{type:...}` dispatch lane or plain `:7271`
  (hub program's P7.x territory, a separate decision);
- decommissioning the `.enable_workerman` mechanism itself.

Those are much later, separate decisions with their own risk analysis. Per
§8, they may reasonably never happen.

---

## 7. Rollback

### 7.1 Triggers — flip a host back when any of these hold

Concrete conditions, per host (not vibes):

1. **Reconnect storm**: sustained reconnect attempts at short intervals
   without a corresponding hub outage — i.e. backoff repeatedly resetting or
   never being confirmed (§4 row 1).
2. **Crash-looping agent**: the cron's restart-if-not-running branch firing
   on consecutive minutes (visible in `cron.output`), or repeated "cannot
   start workerman" emails.
3. **Provisioning/queue failures**: `queue_log` rows for the host stuck
   unprocessed beyond the normal cron cadence, failed provisions, or
   `finished`/`install_progress` callbacks going silent.
4. **Elevated v1 error rates**: sustained `bad_request`/`internal`/
   `auth_failed` replies for the host on the §2.4 instrumentation.
5. **Telemetry/map integrity loss**: bandwidth series flatlined, host info
   stale, or map files diverging from hub output (E1 invariant at risk —
   this one is an *immediate* rollback, since provirted consumes those
   files).
6. **PTY/resource leak**: unbounded PTY count or fd/process growth on the
   host.

### 7.2 Mechanism and speed

Manual rollback of one host is two commands and takes effect **within one
cron minute**:

```sh
rm /home/sites/vps_host_server/.enable_workerman
/home/sites/vps_host_server/workerman/start.php stop
```

Removing the flag makes the next per-minute cron run take the `old_cron=1`
branch — the full legacy block (`provirted.phar` cron actions + HTTP queue
fetches) resumes exactly as it ran before, byte-unchanged (§13.2). No hub
action is required to roll a host back: a stopped agent simply disconnects,
and legacy queue fetching does not depend on Flag A or tokens at all. Leaving
Flag A ON and the token file in place is harmless (the agent is not running)
and preserves the option to re-flip cheaply.

### 7.3 Interaction with the automatic fail-back

The scripts' built-in `old_cron=1` fail-back (§1) is the **first** layer: it
already auto-recovers the "agent won't start" failure class every minute,
with an email, and requires no human. Manual rollback (§7.2) is the second
layer for the failure classes the fail-back is blind to — an agent that
starts fine but misbehaves (triggers 1, 3–6). The two compose cleanly with
one ordering caveat: **stop the agent when removing the flag.** If the flag
is removed but the agent is left running, both paths run simultaneously —
which is mostly benign per §13.6 but re-opens the `cron.cpu_usage`
shared-file collision of §13.5 and doubles telemetry submission. Rollback is
either/or, same as cutover.

### 7.4 Fleet-scale rollback

Because the switch is a per-host file, a fleet rollback is just the §7.2 pair
executed across hosts (the same channel used to place the flag files can
remove them). Hub-side Flag A can be turned OFF afterwards as a belt-and-
suspenders measure once no host is intended to speak v1 — but it is not on
the rollback critical path.

---

## 8. Old/new coexistence — indefinite is an acceptable end state

Consequence of the framing at the top of this document, made explicit:

- **The fleet may sit at 0%, 5%, 50%, or any mixture indefinitely.** Each
  host's gate is independent; the hub serves legacy and v1 hosts
  simultaneously by design (the two-lane dispatch on both ends —
  `BASELINE.md` §11.1 hub-mirroring architecture — was built precisely so
  neither lane's existence perturbs the other).
- **Never cutting over is supported.** The agent's dormancy gates mean the
  new code costs nothing on hosts where it is off; step 3.7 proved the legacy
  path does not know the agent exists. If the WS agent's benefits (real PTYs,
  push-based ops, token auth) are never worth the operational motion for some
  or all hosts, leaving them on cron/provirted forever is a valid decision,
  not technical debt by definition.
- **No wave creates pressure for the next.** Exit criteria gate forward
  motion; nothing gates staying put. The zero-duplicated-business-logic
  discipline (`BASELINE.md` §11.3 — v1 handlers extend/delegate the unchanged
  legacy handlers) is what makes long coexistence cheap: a fix lands once and
  serves both protocols, so a half-migrated fleet does not fork maintenance.

The only standing cost of indefinite coexistence is that the §2.3 functional
gaps stay open on flag-off hosts — which is fine, because on those hosts the
legacy cron is performing those duties, exactly as it does today.
