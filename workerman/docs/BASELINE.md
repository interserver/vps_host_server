# BASELINE — vps_host_server/workerman (step 3.1 verified 2026-07-06; step 3.2 dependency modernization applied 2026-07-06; step 3.3 architecture rewrite applied 2026-07-06; step 3.4 reconnect + step 3.5 v1-protocol mapping applied 2026-07-06; step 3.6 real-terminal pty.* support applied 2026-07-06; step 3.7 boundary audit — legacy provirted+cron+HTTP path confirmed intact, zero code changes, 2026-07-12)

On-disk verified baseline of the OLD host agent before the Phase 3 rebuild
(Workerman v5 / PHP >=8.2, per the ws_revamp plan and the hub's frozen
`docs/PROTOCOL_V1.md`). Everything below was read directly from the files in
this repo — not inferred.

> **Living-doc note:** Sections 1–5 describe the step-3.1 baseline as it was
> **originally captured**. Step 3.2 (dependency modernization) has since landed
> — see **§7** for the completed post-3.2 state. Step 3.3 (the code-migration /
> architecture rewrite that §5 and §6 anticipated) has now also landed — see
> **§8**. Where a version number in §1–§3 reads as a present-tense fact but is
> now stale, it has been annotated inline (`was … as of step 3.1; upgraded to …
> in step 3.2`) so the doc does not self-contradict. §5's description of the
> `stdObject` closure-dispatch architecture is now **historical** (that code was
> deleted in 3.3); it is retained as the "before" picture that §8's "after"
> maps against.

## 1. Version-discrepancy resolution: Workerman was **v4.1.10** as of step 3.1 (upgraded to **v5.2.2** in step 3.2)

**As of step 3.1**, `composer.lock` locked `workerman/workerman` at **v4.1.10**
(ref `e967b79f95b9251a72acb971be05623ec1a51e83`, short `e967b79f95b9`,
released 2023-05-01) and `composer.json` constrained it to `^4.0@stable`. The
"v5.2" figure that appears elsewhere in the program docs applied only to the
**hub repo** (`/home/sites/datacentered`, whose lock has
`workerman/workerman v5.2.2`) — it was never true of this repo at 3.1.

**As of step 3.2** this has been corrected: the constraint is now `^5.2` and
the lock resolves **v5.2.2** (ref `a0866601af06…`), matching the hub. See §7.

There is **no `vendor/` directory installed** in this repo; composer.json +
composer.lock are the only source of truth for dependency versions.

## 2. composer.json as of step 3.1 (superseded by step 3.2 — see §7)

> The `require`/`require-dev` blocks quoted below are the **pre-3.2** contents.
> They have since been modernized; the current on-disk composer.json is
> documented in §7.


- name: `detain/vps-host-service`, type `project`, license `LGPL-2.1-or-later`
- author: Joe Huss <detain@interserver.net>
- autoload: PSR-4 `MyAdmin\VpsHost\ => src/` (note: nothing under `src/` actually
  uses this namespace — files are procedural includes/closures)
- minimum-stability is NOT set in composer.json; the lock records
  `"minimum-stability": "stable"`, `"prefer-stable": false`, plugin-api 2.3.0

```json
"require": {
    "php": ">=5.3.0",
    "ext-curl": "*",
    "ext-pcntl": "*",
    "ext-posix": "*",
    "detain/phpsysinfo": "dev-main",
    "react/child-process": "*",
    "react/event-loop": "*",
    "react/http": "*",
    "roave/security-advisories": "dev-master",
    "workerman/workerman": "^4.0@stable",
    "workerman/globaldata": "dev-master"
},
"require-dev": {
    "phpunit/phpunit": "*",
    "vlucas/phpdotenv": "*",
    "codeclimate/php-test-reporter": "dev-master",
    "satooshi/php-coveralls": "*",
    "codacy/coverage": "dev-master"
}
```

**Recon correction (actioned in 3.2):** at 3.1, `roave/security-advisories`
was in **`require`**, not require-dev. It belongs in `require-dev` (it is a
metapackage that only adds `conflict` rules; shipping it in `require` forces
the floating dev-master resolution onto production installs). **Step 3.2 moved
it to `require-dev`.** See §7.

## 3. composer.lock facts as of step 3.1 (locked 2023-era — superseded by §7)

> This table is the **pre-3.2** lock snapshot. Step 3.2 re-locked on PHP 8.3;
> the post-3.2 resolved versions are in §7. Kept here as the historical
> baseline the migration started from.

Runtime packages (`packages`):

| package | locked version | source ref | committed |
|---|---|---|---|
| workerman/workerman | **v4.1.10** | `e967b79f95b9…` | 2023-05-01 |
| workerman/globaldata | dev-master | `0deee3e161cf…` | 2022-03-30 |
| detain/phpsysinfo | dev-main | `c4910834b753…` | 2022-12-21 |
| roave/security-advisories | dev-master | `11bdd8961a56…` | 2023-05-17 |
| react/child-process | v0.6.5 | `e71eb1aa55f0…` | 2022-09-16 |
| react/event-loop | v1.4.0 | `6e7e587714ff…` | 2023-05-05 |
| react/http | v1.9.0 | `bb3154dbaf2d…` | 2023-04-26 |
| react/promise | v2.10.0 (transitive) | `f913fb8cceba…` | 2023-05-02 |
| react/socket / stream / dns / cache / promise-timer | v1.12.0 / v1.2.0 / v1.10.0 / v1.2.0 / v1.9.0 | — | transitive |
| evenement/evenement v3.0.1, psr/http-message 1.1, fig/http-message-util 1.1.5, ringcentral/psr7 1.3.0 | — | — | transitive |

Dev packages (at 3.1) included phpunit/phpunit 9.6.8, vlucas/phpdotenv v5.5.0,
plus long-abandoned coverage tooling (satooshi/php-coveralls v1.1.0,
codeclimate/php-test-reporter dev-master `f35752238d99`, codacy/coverage
dev-master `656913b35e22`, guzzle/guzzle v3.8.1 (!)) — flagged as candidates
for removal in 3.2 rather than upgrade. **Step 3.2 removed all three coverage
packages** (and with them the transitive guzzle 3.x); phpunit was bumped to
^11 (locked 11.5.56) and phpdotenv to ^5.6 (locked v5.6.3). See §7.

Lock `platform`: `{php: >=5.3.0, ext-curl: *, ext-pcntl: *, ext-posix: *}`.
Content-hash `878b63c0c8d977a5be74ec9542e237de`.

Amusing detail: the locked roave/security-advisories conflict list itself flags
`react/http >=0.7,<1.9` — satisfied only because the lock resolved exactly
v1.9.0.

## 4. Environment facts (this machine, 2026-07-06)

- PHP CLI: **8.3.6** (`/usr/bin/php` → php8.3; no php8.2 binary, 8.3 satisfies a
  PHP >=8.2 target)
- Composer: **2.10.1**
- Hub repo precedent: `/home/sites/datacentered/composer.lock` locks
  `workerman/workerman` **v5.2.2** running on this same PHP 8.3 — so
  Workerman ^5.2 on PHP 8.3 is proven viable in this environment.
- This repo: branch `master`, clean except unrelated modified
  `cloudinit/n99panel.yaml` (untouched).

## 5. Architecture characterization (what step 3.3 replaces)

Layout under `workerman/`: `start.php` (entry), `src/bootstrap.php`,
`src/stdObject.php`, `src/Config/`, `src/Data/xml2array.php`,
`src/Events/*.php` (25 closure files), `src/Stats/{Network,System,Storage}.php`,
`src/Tasks/{run,vps_get_cpu,vps_get_list,vps_queue,vps_update_info,xml2array}.php`,
`src/Workers/{VpsServer,Task,GlobalData}.php`, `tests/test.php`.

### Entry & workers
- `start.php` defines `GLOBAL_START`, includes `src/bootstrap.php`
  (pcntl/posix checks + `vendor/autoload.php`), globs `src/Workers/*.php`,
  `Worker::runAll()`. Three workers:
  - `VpsServer.php` — `new Worker()` (no listen socket; pure client process),
    name `VpsHostWorker`.
  - `Task.php` — `new Worker('Text://127.0.0.1:55552')`, count 2; globs
    `src/Tasks/*.php` into a second stdObject (`mytasks`) and dispatches by
    `call_user_func([$task_worker->mytasks, $task_data['type']], args)`.
    Optional `lock:true` uses a **busy-wait CAS spin loop** on GlobalData
    `busy` (0↔1) — a blocking anti-pattern to eliminate in the rebuild.
  - `GlobalData.php` — `new \GlobalData\Server('127.0.0.1', '55553')`.

### stdObject closure dispatch (confirmed)
`src/stdObject.php` is a property bag whose magic `__call` looks up
`$this->{$method}` and, if it is a callable/Closure, invokes it with `$this`
prepended as arg 0 (`stdObject.php:45-53`). `VpsServer.php:8-16` builds one
`$events = new stdObject()` in `onWorkerStart` and assigns every file in
`src/Events/*.php` (each `return function ($stdObject, ...) {...}`) as a
dynamic property named after the file. So "methods" like
`$stdObject->vps_get_list()` are file-loaded closures dispatched via `__call`.
No namespaces, no types, no autoloaded classes — this is exactly the pattern
step 3.3 replaces with real handler classes.

### Message dispatch
`src/Events/onMessage.php:17-136` is one big `switch ($data['type'])` over
JSON-decoded frames from the hub: `login`, `timers`, `self-update` (runs
`update.sh` via `exec` after a random 2-60s sleep!), `ping`/`pong`,
`get_map`, `phpsysinfo`, `run`/`run_list`/`running`/`stop_run` (streamed
command execution via `React\ChildProcess\Process` on
`Worker::getEventLoop()`, stdout/stderr forwarded as `{type:"running"}`
frames, exit as `{type:"ran"}`), default → safeEcho "Unhandled".
It also imports the removed-in-v5 `Workerman\Lib\Timer` alias (v4 style).

### Connection & "auth"
`src/Events/onWorkerStart.php` reads `config.ini.dist`+`config.ini`, and opens
a single `AsyncTcpConnection` to `ws://mynew.interserver.net:7272` with
`transport='ssl'` (self-signed cert auto-generated to `src/myadmin.crt|key`)
or plain `:7271`. `src/Events/onConnect.php` sends
`{type:"login", name:<hostname>, module:"vps", room_id:1, ima:"host"}` —
**no token, no credential**; hub authenticates by source IP only. PROTOCOL_V1
§2.1/§3 replaces this with `auth.hello{role:"host", host_id, token}` →
`auth.welcome`, token AND source-IP validated, `auth.hello` mandatory first
frame.

### Ops that map to PROTOCOL_V1
- `get_map` (`src/Events/get_map.php`) — writes `vps.mainips|slicemap|ipmap|vncmap`
  files in the repo parent dir, rebuilds ebtables / xinetd VNC entries on
  change, then chains `vps_get_list()`. → v1 `config.maps` (§2.6).
- `vps_get_list` / `vps_update_info` (Events) — fire-and-forward: open
  `Text://127.0.0.1:55552`, send `{type, lock:true, args:{type: vzctl|kvm}}`
  to the local TaskWorker, forward the task result verbatim to the hub
  connection. → v1 `telemetry.inventory` / `telemetry.host` (§2.5).
- `phpsysinfo` → v1 `telemetry.sysinfo`; `run/*` family → v1 `cmd.*` (§2.2);
  `self-update` → v1 `agent.update` (§2.8).

## 6. What step 3.2 must do (planned — now DONE, see §7 for outcome)

- Target **PHP >=8.2** in `require.php` (env has 8.3.6; drop the `>=5.3.0` fossil).
- Bump `workerman/workerman` to **^5.2** (hub already runs v5.2.2 on this box;
  note v5 removes the `Workerman\Lib\Timer` alias used by several Events files —
  code migration is 3.3's job, but the constraint lands in 3.2).
- Pin the floating deps to caret ranges / move as appropriate:
  - `workerman/globaldata` dev-master (`0deee3e161cf`) → pinned release
  - `detain/phpsysinfo` dev-main (`c4910834b753`) → pinned
  - `roave/security-advisories` dev-master → move from `require` to
    **`require-dev`** (keep dev-master; that is its only publishing model)
- Keep `ext-curl`, `ext-pcntl`, `ext-posix` platform requires.
- Audit/bump the `*`-constrained react packages: `react/child-process`
  (locked v0.6.5; hub uses v0.6.7), `react/event-loop` (v1.4.0; hub v1.6.0),
  `react/http` (v1.9.0; hub v1.11.0) — replace `*` with caret constraints.
- Prune dead require-dev (codeclimate/php-test-reporter, satooshi/php-coveralls,
  codacy/coverage — all abandoned, drag in guzzle 3.x) and pin
  `phpunit/phpunit` (e.g. ^10/^11) and `vlucas/phpdotenv` if kept.
- Run `composer update` on PHP 8.3 and commit the new lock.

## 7. Step 3.2 — dependency modernization COMPLETE (applied 2026-07-06, reviewed CLEAN)

Step 3.2 is **done**. It was a pure dependency-metadata change:
composer.json + composer.lock only — **no `src/` / application code was
touched.** A separate Review Agent verified the result CLEAN. All figures
below were re-read directly from the on-disk `composer.json` / `composer.lock`
in this repo (not carried over from the plan).

### 7.1 Final `require` (composer.json, verified)

| package | 3.1 constraint → 3.2 constraint | locked (post-3.2) |
|---|---|---|
| php | `>=5.3.0` → **`>=8.2`** | platform `>=8.2` |
| ext-curl / ext-pcntl / ext-posix | `*` → `*` (unchanged) | — |
| workerman/workerman | `^4.0@stable` → **`^5.2`** | **v5.2.2** (`a0866601af06…`) |
| workerman/globaldata | `dev-master` → **`^1.0.6`** | **v1.0.6** (`9f5082ad712d…`) |
| detain/phpsysinfo | `dev-main` (floating) → **`dev-main#c4910834b753f8929f2e8a4429be9466a29e46b5`** (exact commit pin) | dev-main @ `c4910834b753…` (same commit as 3.1) |
| react/child-process | `*` → **`^0.6.7`** | v0.6.7 (`970f0e719455…`) |
| react/event-loop | `*` → **`^1.6.0`** | v1.6.0 (`ba276bda6083…`) |
| react/http | `*` → **`^1.11.0`** | v1.11.0 (`8db02de41dcc…`) |

### 7.2 Final `require-dev` (composer.json, verified)

| package | 3.1 → 3.2 | locked (post-3.2) |
|---|---|---|
| phpunit/phpunit | `*` → **`^11.0`** | 11.5.56 |
| vlucas/phpdotenv | `*` → **`^5.6`** | v5.6.3 |
| roave/security-advisories | **moved from `require`** → `require-dev` (still `dev-master`) | dev-master (`4d2ca579be8e…`) |
| codeclimate/php-test-reporter | **removed** (abandoned, unreferenced) | — |
| satooshi/php-coveralls | **removed** (abandoned, unreferenced) | — |
| codacy/coverage | **removed** (abandoned, unreferenced) | — |

The three removed coverage packages were confirmed genuinely unreferenced
anywhere in the repo; their removal also drops the transitive guzzle 3.x.

Lock metadata post-3.2: content-hash `b6afe5fe1ef915474e9b6bee63a7d7f4`,
`minimum-stability: stable`, `prefer-stable: false`, plugin-api `2.9.0`,
platform `{php: >=8.2, ext-curl, ext-pcntl, ext-posix}`,
`stability-flags: {detain/phpsysinfo: 20, roave/security-advisories: 20}`.
(`workerman/coroutine` v1.1.5 appears as a new transitive dep pulled in by
workerman/workerman v5.)

### 7.3 phpsysinfo pin rationale (important)

`detain/phpsysinfo` is **the user's own fork/dependency** and it is **not**
pinned to a semver tag — it is pinned to the **exact commit
`c4910834b753f8929f2e8a4429be9466a29e46b5`**, which is the same commit the 3.1
lock was already resolving. This is deliberate:

- Upstream `detain/phpsysinfo` has **no tagged release newer than the
  already-in-use commit.** Its latest real tag (v3.4.1) is roughly **11 months
  OLDER** than this locked commit — so pinning to that tag would have been a
  behavior **regression**, not a modernization.
- Commit-pinning therefore gives us the best available outcome: **zero
  behavior change** (byte-identical to what was already running) while making
  the dependency **reproducible / non-floating** — it can no longer silently
  drift on a future `composer update` the way the bare `dev-main` constraint
  could.

> **FOLLOW-UP RECOMMENDATION (deferred, non-blocking):** tag a proper semver
> release on the `detain/phpsysinfo` upstream repo (at or after this commit)
> so this dependency can eventually move from a raw commit pin to a real
> semver constraint (e.g. `^3.5`). Until such a tag exists, the commit pin is
> the correct choice and should stay.

### 7.4 IMPORTANT — expected non-functional state after step 3.2

> **As of step 3.2, this repo's application code does NOT yet run correctly.**
> This is **expected and intentional**, not a regression introduced by the
> dependency bump:
>
> - `workerman/workerman` **v5 removed `Workerman\Lib\Timer`**, which the old
>   agent still imports/uses in ~6 files (notably `src/Events/onMessage.php`
>   and other Events closures — see §5).
> - The whole **`stdObject` closure-dispatch architecture** (§5) needs to be
>   replaced with real handler classes for a v5 world.
>
> Both of these are **deferred to step 3.3** (the code-migration unit of
> work). Step 3.2 deliberately lands only the dependency constraints and lock;
> making the code actually run under Workerman v5 is 3.3's job. Do not treat
> the current non-running state as a bug in 3.2.

## 8. Step 3.3 — Architecture: Agent + handler registry (stdObject replaced) COMPLETE (applied 2026-07-06, reviewed CLEAN ×2)

Step 3.3 is **done**. It replaced the entire `stdObject` dynamic-property /
closure-dispatch architecture (§5) with explicit, namespaced, typed classes
under the existing `MyAdmin\VpsHost\` PSR-4 root, and completed the
`Workerman\Lib\Timer → Workerman\Timer` migration that §6 flagged as 3.3's job.
Two Review→Fix rounds passed; the result was re-reviewed CLEAN. Everything
below was read directly from the on-disk `src/` files (not carried over from
the plan).

### 8.1 The new architecture

| Class (`src/…`) | Namespace | Responsibility |
|---|---|---|
| `Agent.php` | `MyAdmin\VpsHost\Agent` | The live state + lifecycle callbacks of the `VpsHostWorker` websocket client. Former stdObject dynamic properties are now **real typed public properties** (`$vps_list`, `$bandwidth`, `$traffic_last`, `$timers`, `$ipmap`, `$running`, `$type`, `$hostname`, `$config`, `$conn`, …). Former `Events/*.php` closures are now **real methods** (lifecycle: `onWorkerStart`/`onConnect`/`onMessage`/`onClose`/`onError`/`onWorkerStop`; plus `checkHeartbeat`, `sendPing`, `sendPong`, `setupTimers`, `addTimer`, `validIp`, `getSslContext`, `get_map_timer`, `get_vps_ipmap`, `get_vps_iptables_traffic`, `vps_iptables_traffic_rules`, `vps_get_cpu`, `vps_get_list`, `vps_get_traffic`, `vps_queue_timer`, `vps_update_info`, `vps_update_info_timer`). Holds a `MessageDispatcher` (constructor-injectable; defaults to a fresh one). |
| `MessageDispatcher.php` | `MyAdmin\VpsHost\MessageDispatcher` | Explicit `type => handler` registry replacing the old `switch($data['type'])` in `Events/onMessage.php`. Its `dispatch(Agent, AsyncTcpConnection, array $data)` looks up `$data['type']` in the handler map and calls `->handle(...)`; the **default branch preserves the exact "Unhandled Mesage Type ..." typo** (note the missing `s` in "Message") for byte-for-byte behavioral parity with the old default case. Also exposes `register()` / `getHandlers()`. |
| `Handlers/MessageHandlerInterface.php` | `…\Handlers\MessageHandlerInterface` | The one-method contract every handler implements: `handle(Agent $agent, AsyncTcpConnection $conn, array $data): void`, where `$data` is the full json-decoded frame. |
| `Handlers/*Handler.php` (11) | `…\Handlers\*` | One class per message type. Bodies ported **verbatim** from the corresponding arm of the old `onMessage.php` switch (or from the standalone `Events/get_map.php` / `Events/phpsysinfo.php` closures). |
| `TaskRegistry.php` | `MyAdmin\VpsHost\TaskRegistry` | Replaces the **second** stdObject instance (`$mytasks`) used by the Task worker. `load($dir)` globs `src/Tasks/*.php` (each still `return function (...) {...}`) into a `name => callable` map; `call($type, ...$args)` invokes the task closure **with the registry itself prepended as arg 0** — faithfully replicating the old `stdObject::__call` arg-0-prepend convention (see §5) so a task can still call sibling tasks, e.g. `$tasks->call('xml2array', ...)`. `has($type)` guards; an undefined task throws the same "Call to undefined task …" shape. |

**How routing now works:** `Agent::onMessage()` refreshes `lastMessageTime`,
`json_decode`s the frame, guards it (see §8.4), then calls
`$this->dispatcher->dispatch($this, $conn, $data)`. The dispatcher indexes its
handler map by `$data['type']` and delegates to the matching handler's
`handle()`. **No dynamic-property magic, no `__call`, no closures-as-properties**
— every route is an explicit array entry pointing at a real class, and every
"method" is a real typed method resolvable by static analysis.

### 8.2 Old → new mapping (all 25 `src/Events/*.php` + `stdObject.php`, now deleted)

The 25 deleted `Events/*.php` files (confirmed via `git status`: all 25 staged
`D`) plus `stdObject.php` land as follows. 23 became **Agent methods**, 2
became **Handler classes**; the switch arms that lived *inside* `onMessage.php`
(and had no standalone file) became the remaining 9 handlers.

| Old `Events/*.php` | New home |
|---|---|
| `onWorkerStart.php` | `Agent::onWorkerStart()` |
| `onConnect.php` | `Agent::onConnect()` |
| `onMessage.php` | `Agent::onMessage()` **+** its `switch` → `MessageDispatcher` + the 11 handlers |
| `onClose.php` | `Agent::onClose()` |
| `onError.php` | `Agent::onError()` |
| `onWorkerStop.php` | `Agent::onWorkerStop()` |
| `checkHeartbeat.php` | `Agent::checkHeartbeat()` |
| `sendPing.php` | `Agent::sendPing()` |
| `sendPong.php` | `Agent::sendPong()` |
| `setupTimers.php` | `Agent::setupTimers()` |
| `addTimer.php` | `Agent::addTimer()` |
| `validIp.php` | `Agent::validIp()` |
| `getSslContext.php` | `Agent::getSslContext()` (with the cert-path micro-fix, §8.3) |
| `get_map_timer.php` | `Agent::get_map_timer()` |
| `get_vps_ipmap.php` | `Agent::get_vps_ipmap()` |
| `get_vps_iptables_traffic.php` | `Agent::get_vps_iptables_traffic()` |
| `vps_iptables_traffic_rules.php` | `Agent::vps_iptables_traffic_rules()` |
| `vps_get_cpu.php` | `Agent::vps_get_cpu()` |
| `vps_get_list.php` | `Agent::vps_get_list()` |
| `vps_get_traffic.php` | `Agent::vps_get_traffic()` |
| `vps_queue_timer.php` | `Agent::vps_queue_timer()` |
| `vps_update_info.php` | `Agent::vps_update_info()` |
| `vps_update_info_timer.php` | `Agent::vps_update_info_timer()` |
| `get_map.php` | `Handlers\GetMapHandler` (map-file change detection + ebtables/xinetd VNC rebuild, then chains `Agent::vps_get_list()`) |
| `phpsysinfo.php` | `Handlers\PhpsysinfoHandler` (runs phpsysinfo via the TaskWorker, replies gzcompress+base64) |
| `stdObject.php` | **deleted** — property-bag/`__call` role split between `Agent` (real properties/methods) and `TaskRegistry` (the `$mytasks` role) |

The 9 handlers with **no standalone old file** (they were `case` arms inside
`onMessage.php`): `LoginHandler` (`login` → `setupTimers` when `ima==host`),
`TimersHandler` (`timers` → reports `$agent->timers`), `SelfUpdateHandler`
(`self-update` → random splay then `update.sh` + `start.php reload`),
`PingHandler` (`ping` → `sendPong`), `PongHandler` (`pong` → no-op;
`lastMessageTime` already refreshed in `onMessage`), `RunHandler` (`run` → full
`React\ChildProcess\Process` streaming, `{type:"running"}` stdout/stderr frames
+ `{type:"ran"}` exit frame), `RunListHandler` (`run_list` → reports
`$agent->running`), `RunningHandler` (`running` → forwards interactive stdin),
`StopRunHandler` (`stop_run` → closes pipes + `SIGKILL`).

**Callers updated** (behavior-preserving):
- `src/Workers/VpsServer.php` — `onWorkerStart` now does `new Agent()` (was
  `new stdObject()` + globbing `Events/*.php` into it). Global var name kept as
  `$events` for continuity.
- `src/Workers/Task.php` — now `new TaskRegistry()` + `->load(__DIR__.'/../Tasks')`
  and `$mytasks->call($type, $args)` (was `call_user_func([$mytasks,$type],…)`).
  The **busy-wait CAS lock is intentionally preserved as-is** (still the §5
  anti-pattern) — de-blocking it is out of scope for 3.3.
- `src/bootstrap.php` — dropped the `stdObject.php` include (autoloading handles
  the new PSR-4 classes).
- `src/Tasks/vps_get_list.php` — one line changed: the sibling-task call went
  from stdObject `__call` magic (`$stdObject->xml2array(...)` implied) to the
  explicit `$stdObject->call('xml2array', $out, 1, 'attribute')` — the closure's
  first parameter is now the `TaskRegistry`, and it invokes `->call()` on it.
  (The parameter is still named `$stdObject` in that file; only the dispatch
  mechanism changed.)

### 8.3 `getSslContext()` cert-path micro-fix — **KEEP** (judgment call, reviewed & approved)

The old `Events/getSslContext.php` pointed `local_cert`/`local_pk` at
`src/Events/myadmin.crt` / `.key`. **That path never existed anywhere in the
repo** — the self-signed cert is generated one directory up (in `src/`, by
`onWorkerStart`, as `src/myadmin.crt|key`). So the old path was a **dead,
unreachable-in-practice code branch**: it is only consulted when
`config option use_ssl == 1`, and `use_ssl` **defaults to `0`**, so no live
deployment ever exercised it — and had one tried, it would have failed (cert
not found at that path).

The Step Agent corrected it to point at the actual generated location
(`__DIR__.'/myadmin.crt'` / `.key`, i.e. `src/`). A Review Agent explicitly
reviewed this as a judgment call (an architecture-refactor step making an
unplanned behavior change) and ruled **KEEP**: the old path *could never have
worked*, so the fix is a **zero-live-behavior-change improvement** (it converts
an always-broken branch into a working one; it changes nothing for the
`use_ssl == 0` default path everyone actually runs). This is consistent with
how earlier phases of this program handled similar dead-path fixes (cf. §7.3's
commit-pin reasoning: prefer the correct-and-reproducible outcome when the old
state was already non-functional). An inline comment at the method documents
the correction so the change is not mistaken for an accident.

### 8.4 Malformed-JSON dispatch guard — regression found & fixed (robustness)

`MessageDispatcher::dispatch()` is strictly typed: `dispatch(Agent, AsyncTcpConnection, array $data)`.
The **initial** 3.3 rewrite passed `json_decode($data, true)` straight into it
with no guard. On a malformed / non-JSON wire frame, `json_decode` returns
`null` (not an array) — which under PHP 8's `array` type hint throws an
**uncaught `TypeError`**, **killing the worker process**. That is a regression
versus the OLD behavior: the old `switch($data['type'])` in `onMessage.php`
would merely emit a PHP warning on the bad `$data['type']` access and **fall
through to the `default` case** — logging "Unhandled Mesage Type" and **keeping
the process alive**.

A Review Agent caught this; a Fix Agent added a guard in `Agent::onMessage()`
**before** the dispatch call:

```php
$data = json_decode($data, true);
if (!is_array($data) || !isset($data['type'])) {
    // malformed/non-JSON frame - mirror the old onMessage.php default case
    Worker::safeEcho("Unhandled Mesage Type \n");
    return;
}
$this->dispatcher->dispatch($this, $conn, $data);
```

This restores the **exact old log string** (same "Mesage" typo) and the
**graceful-continue** semantics. Why it matters: the host agent is a
**long-running network service** holding a single persistent WSS link to the
hub — a single crafted or corrupted frame must not be able to crash the
process (which would drop telemetry/command handling for every VPS on that
host until Workerman respawns it). The guard makes a bad frame a no-op log
line instead of a process-death event. Re-reviewed CLEAN.

### 8.5 Timer migration completed (closes the §6 item)

The 6-file `Workerman\Lib\Timer → Workerman\Timer` migration that §6 flagged as
necessary (Workerman v5 removed the `Workerman\Lib\Timer` alias) was completed
as part of this rewrite. The **sole live `Timer::add()` / `Timer::del()` call
site** is now `Agent::addTimer()`, which `use Workerman\Timer;` at the top of
`Agent.php`. With this, the "expected non-functional state" warning of §7.4 is
resolved: the code now targets the v5 Timer API.

### 8.6 Out of scope / untouched

- `tests/test.php` (pre-existing) references nonexistent classes and was
  **already broken legacy cruft before 3.3**. It is **not** this step's
  responsibility and was deliberately left untouched — do not read its broken
  state as a 3.3 regression.
- The Task worker's **busy-wait CAS spin lock** (§5) is preserved verbatim; the
  blocking anti-pattern is a separate future cleanup.
- No dependency changes: `composer.json` / `composer.lock` were **not** touched
  in 3.3 (that was 3.2's job — §7).

### 8.7 Documentation touch-ups made alongside this doc

Class-level PHPDoc was already present on `Agent`, `MessageDispatcher`,
`MessageHandlerInterface`, all 11 handlers, and `TaskRegistry` (the latter
explicitly documenting the arg-0-prepend convention on `call()`). This
documentation pass additionally added **method-level PHPDoc** to the previously
undocumented ported methods on `Agent` (`onWorkerStart`, `onMessage`,
`setupTimers`, `addTimer`, `validIp`, `getSslContext`, `get_vps_ipmap`,
`get_vps_iptables_traffic`, `vps_iptables_traffic_rules`, `vps_get_cpu`,
`vps_get_traffic`, `vps_queue_timer`, `vps_update_info`,
`vps_update_info_timer`). **Docblocks/comments only — no application logic was
changed** in this pass, and `php -l` is clean on every file touched.

## 9. Step 3.4 — ReconnectManager + v5 heartbeat (self-healing reconnection) COMPLETE (applied 2026-07-06, reviewed CLEAN ×2)

Step 3.4 is **done**. It added `src/ReconnectManager.php` and modified
`src/Agent.php` to replace `Worker::stopAll()`-on-disconnect with an in-process
exponential-backoff reconnect loop plus Workerman v5's built-in ws heartbeat,
satisfying the plan's step 3.4 success criterion ("replace onClose→
`Worker::stopAll()` with backoff + built-in heartbeat; survives hub bounce,
reconnects with backoff, no systemd needed"). Two Review→Fix rounds passed and
a Test Agent independently re-verified every empirical claim below with a live
smoke test against a real local TCP server plus new PHPUnit coverage (backoff
math, dedup-flag behavior, and `Agent`'s connection-loss wiring — verified by
`tests/…`; see the step 3.4 `ws_progress.md` row for exact pass/fail counts).
Everything below was read directly from the on-disk `src/ReconnectManager.php`
and `src/Agent.php`, not carried over from the plan.

### 9.1 The backoff algorithm and its rationale

`ReconnectManager::nextDelay()` computes:

```
delay = min(baseDelay * multiplier^attempts, maxDelay)  ± (jitterRatio * delay)
```

with defaults `baseDelay=2.0s`, `multiplier=2.0`, `maxDelay=60.0s`,
`jitterRatio=0.2` (±20%), floored at `0.1s`. So the un-jittered progression is
**2, 4, 8, 16, 32, 60, 60, …** seconds — each value then perturbed ±20% to
de-synchronize a fleet of hosts all reconnecting to the same hub at once
(prevents a thundering-herd retry spike). `scheduleReconnect()` reads the delay,
increments `attempts`, and arms a **one-shot** (`persistent=false`)
`Workerman\Timer` that runs the reconnect callable.

**Rationale — no max-attempts cutoff (retries forever, capped at 60s):** this is
a deliberate, reviewed-and-approved choice for a long-running unattended fleet
host agent. It should self-heal through an *arbitrarily* long hub outage without
anyone touching each host. Review explicitly confirmed this is safe on two axes:
(1) **no counter overflow** — `attempts` can grow unbounded but the delay math
degrades gracefully far past very high attempt counts (the `min(…, maxDelay)`
clamps `multiplier^attempts` regardless of how large it gets), and (2) **no
resource leak over long uptimes** — each attempt uses a one-shot timer that
auto-removes after firing, and a **single connection object is reused** across
all attempts rather than accumulating new ones (see §9.4).

### 9.2 Reset trigger design and its race-safety rationale

The backoff counter is reset by `ReconnectManager::confirmConnected()` (sets
`attempts = 0`). The Agent calls it from **`onMessage`** — on the **first
application-level frame received from the hub** — **NOT from `onConnect`**.

**Why not `onConnect`:** a hub stuck in a crash loop can accept the TCP/WS
connection and then immediately die *before any real protocol exchange
completes*. Resetting on `onConnect` would treat that half-open, useless socket
as "success", collapse the backoff to its base delay, and produce a **fast
reconnect storm** hammering the flapping hub. Resetting only after a genuine
application frame has round-tripped means backoff is reset only when the link is
provably carrying real traffic.

**Race-safety (reviewed & confirmed):** stale frames cannot trigger a false
reset. `TcpConnection::destroy()` **nulls the callbacks** (`onMessage`/`onClose`/
`onError`) before any stale frame from a dead connection could arrive, so
`onMessage` won't fire for a torn-down link. And the `scheduled` dedup flag
guarantees at most one reconnect timer exists at a time, so overlapping
drop/reconnect events cannot double-arm.

### 9.3 The 3 replaced `Worker::stopAll()` connection-loss call sites

All three old connection-loss `Worker::stopAll()` sites are gone; each now
funnels into the single `ReconnectManager` backoff path:

| # | Site | Before | After |
|---|---|---|---|
| 1 | `onClose` (link dropped) | `Worker::stopAll()` — kills the whole process | `reconnectManager->scheduleReconnect(reconnectToHub, 'connection closed')` |
| 2 | `onError` (CONNECT_FAIL) | `Worker::stopAll()` | `reconnectManager->scheduleReconnect(reconnectToHub, 'connect failed: …')` (defense-in-depth, see §9.5) |
| 3 | `checkHeartbeat` timeout branch | `$conn->close()` **+** `Worker::stopAll()` | just `$conn->close()` — which fires `onClose`, funneling into site #1's backoff path |

A new **guard** was added at the top of `checkHeartbeat`: it returns
immediately unless `$this->conn` is a live `AsyncTcpConnection` in
`STATUS_ESTABLISHED`. While disconnected / mid-reconnect there is nothing to
ping and nothing to close — the `ReconnectManager` owns recovery until the link
is back up, so heartbeat checks are skipped.

**Confirmed via grep + review:** zero other connection-loss-related
`stopAll()` call sites remain anywhere in `src/`. The **one** remaining
`stopAll()` in the codebase is Workerman's own **vendor-internal
fatal-callback-exception path**, which is unrelated to hub-connection loss and
was correctly left untouched.

### 9.4 Vendor-behavior finding #1 — `destroy()` nulls callbacks; reuse the connection object

Discovered by reading the **actual installed Workerman v5.2.x vendor source**
(not assumed): `TcpConnection::destroy()` **nulls the `onMessage`/`onClose`/
`onError` callbacks after firing `onClose`** (`TcpConnection.php:1199`,
"Cleaning up the callback to avoid memory leaks"). Consequences the
implementation is built around:

- A bare `$conn->reconnect()` would come back with **no callbacks wired** and
  crash `baseRead` on the first frame. So `Agent::wireConnection()` **re-wires
  all callbacks before every** `reconnect()` (via `Agent::reconnectToHub()`),
  in addition to before the initial `connect()`.
- The implementation **reuses the SAME connection object** across all reconnect
  attempts rather than constructing a fresh `AsyncTcpConnection` each time. A
  fresh object per attempt would **leak entries** in `AsyncTcpConnection`'s
  internal static `$connections` registry, because a `CONNECT_FAIL` never runs
  `destroy()` on the abandoned object, so it would linger in that registry
  forever. `reconnectToHub()` calls `reconnect(0)` on the one retained object,
  which resets its status back to `STATUS_INITIAL` and reconnects immediately.

### 9.5 Vendor-behavior finding #2 — `onError` also schedules (defense-in-depth), corrected understanding

`Agent::onError()` **independently** schedules a reconnect for `CONNECT_FAIL`,
in addition to the reconnect that `onClose` will schedule. This is a deliberate
**defense-in-depth** measure. Important corrected understanding:

- This is **NOT** because `onClose` fails to fire on this Workerman version.
  Both Review and Test **empirically confirmed** `onClose` **DOES** eventually
  fire in the traced connect-fail paths for the installed v5.2.x (a failed
  connect runs `destroy()`, which fires `onClose`). An **earlier code comment
  wrongly claimed onClose was skipped** and was **corrected during Review**.
- The redundant scheduling is **kept anyway** because it is **provably
  harmless** regardless of Workerman-version behavior: `ReconnectManager`'s
  `scheduled` dedup flag makes a double-schedule for the same drop a safe
  no-op, so the belt-and-suspenders scheduling costs nothing while keeping
  recovery robust against any future version that *did* skip `onClose`.

### 9.6 Dual-layer heartbeat

Heartbeat is now **dual-layered**, and it is important to understand which
layer actually detects a dead link:

- **Layer 1 — Workerman v5 built-in ws-protocol ping** (`wireConnection()` sets
  `$ws_connection->websocketPingInterval`, from `config heartbeat.ws_ping_interval`,
  default ~55s). This sends a protocol-level ping control frame and provides a
  low-level TCP/WS keepalive that holds NAT/firewall state open.
- **Layer 2 — app-level JSON `ping`/`pong`** (`checkHeartbeat` / `sendPing` /
  `sendPong`, **unchanged in behavior** from before 3.3/3.4). This remains the
  **actual mechanism that detects a truly dead/stuck link** and triggers a
  reconnect.

**Why both are needed:** protocol-level pongs are handled by Workerman
*internally* inside the Ws protocol and **never reach the application's
`onMessage`** — so only the app-level ping/pong round-trip can observe an
*application-level* hang (a link that is TCP-alive but no longer serving real
traffic). `onConnect` resets `$global->lastMessageTime = time()` on every
(re)connection, giving each fresh link a **full heartbeat-timeout window**
before `checkHeartbeat` may judge it stale (rather than inheriting a stale
timestamp from before the disconnect).

### 9.7 "No systemd needed" — how this meets the success criterion

The old design killed the entire PHP process on any hub-link loss
(`Worker::stopAll()`) and **relied on an external process supervisor (e.g.
systemd) to restart it** so it could reconnect. Step 3.4 removes that
dependency: on a hub bounce or network blip the **agent process stays alive**
and **self-heals its own WS connection** in-process via the backoff loop — a few
`[Reconnect]` log lines and an automatic re-login once the hub is reachable
again. No external supervisor is required to restart the PHP process to recover
the hub link.

> **Distinct from Workerman's own worker-process-crash-respawn.** Workerman's
> master process still independently respawns a *worker process* that actually
> **crashes** — that mechanism is unrelated to hub-connection loss and is
> unchanged. Step 3.4's point is narrower and specific to the success
> criterion: a *hub disconnect* (which is not a process crash) no longer needs
> to escalate into a process death + external restart to be recovered.

### 9.8 Documentation touch-ups made in this pass

Class-level PHPDoc was already present on `ReconnectManager` (documenting the
backoff formula, the confirm-on-first-frame reset choice, and the no-cutoff
design decision) and on `Agent`. This documentation pass additionally curated
docblocks on `ReconnectManager::getAttempts()` / `isScheduled()` (documenting
the unbounded-attempts-but-capped-delay property and the dedup-flag safety
guarantee), and added/expanded method-level PHPDoc on the modified `Agent`
methods `onError` (the corrected defense-in-depth rationale) and
`checkHeartbeat` (the ESTABLISHED-state guard and the close-funnels-to-backoff
behavior). The existing docblocks on `wireConnection`, `reconnectToHub`,
`onConnect`, `onMessage`, and `onClose` — which already document the callback
re-wire, the single-object reuse, the reset-on-first-frame choice, and the
backoff-instead-of-stopAll behavior — were left in place. **Docblocks/comments
and this BASELINE §9 only — no application logic was changed** in this pass, and
`php -l` is clean on every file touched (`src/ReconnectManager.php`,
`src/Agent.php`).

## 10. Pre-existing production bug found + fixed: React ChildProcess vs Workerman v5 event-loop incompatibility (applied 2026-07-06, verified independently ×2)

Step 3.5's implementation was **interrupted mid-flight** (session/rate-limit
cutoff), then repaired; during the aftermath of that repair a **genuine,
significant, pre-existing production bug** was discovered and fixed. That bug —
not the interruption — is the substance of this section. Everything below was
read directly from the on-disk `src/ReactLoopBridge.php`,
`src/ReactLoopBridgeTimer.php`, `src/Handlers/RunHandler.php`, and
`src/Tasks/vps_queue.php`, and cross-checked against `git show HEAD` for the
"predates this program" claim.

### 10.1 How this fix came to be found (the C5-recovery incident, briefly)

So a future reader need not reconstruct this from the `ws_progress.md` activity
log:

- Step 3.5 was first attempted but the implementing agent was **interrupted by a
  session/rate-limit** part-way through, leaving `src/Handlers/RunHandler.php` in
  a **runtime-broken state** — an abandoned mid-refactor with closures capturing
  an out-of-scope `$conn` variable and a bogus reference to a nonexistent class.
- Per the program's **C5 recovery rule**, a fresh assessment agent caught the
  broken state rather than building on top of it. A **Fix Agent** repaired
  `RunHandler.php` back to its proven-correct step-3.3 shape (plain `handle()`,
  no broken extraction); an independent **Review Agent** verified that repair
  CLEAN.
- While investigating a concern raised **during that repair's aftermath**, the
  real bug below surfaced. It is therefore a **direct value-add of this cleanup
  effort** — found because the C5 recovery forced a careful re-read of the
  `run`-command child-process path — **not** a regression introduced by steps
  3.1–3.4.

### 10.2 The bug — what breaks and why

The `run` command path spawns a shell command as a `React\ChildProcess\Process`
and calls `->start($loop)`. React requires that `$loop` be a real
`React\EventLoop\LoopInterface`. This repo's code was instead passing
**`Worker::getEventLoop()`** — Workerman's own `Workerman\Events\EventInterface`
object, which is **NOT** a `LoopInterface`. Two failure modes:

1. **Immediate throw.** Under Workerman v5.2 + react/child-process v0.6.7,
   `Process::start(Worker::getEventLoop())` throws
   `InvalidArgumentException('Argument #1 ($loop) expected
   null|React\EventLoop\LoopInterface')` — so **any real invocation of the `run`
   command dies at runtime** before the child even starts.
2. **`null` is silently broken too.** The "obvious" fix of passing `null` (or
   omitting the arg) makes React fall back to its **own global `Loop::get()`
   singleton** — but that loop is **never ticked by anything**, because
   Workerman owns and blocks in the *real* running loop. Consequence: child
   stdout/stderr `data` events and the `exit` event would only ever flush at
   **worker shutdown**, not live — i.e. **no real-time output streaming** and a
   **shutdown-time stall** on any still-running child. Verified empirically (see
   §10.4).

### 10.3 Root cause — this PREDATES the entire modernization program

This is **not** a regression introduced by steps 3.1–3.4. The identical
`Worker::getEventLoop()` → `start($loop)` pattern existed in the **original
pre-3.3 legacy code**. Evidence, re-derivable at will:

```
$ git show HEAD:workerman/src/Events/onMessage.php
…
69:   $loop = Worker::getEventLoop();
…
73:   $stdObject->running[$data['id']]['process']->start($loop);
```

The old legacy `run` case in `Events/onMessage.php` (lines 69/73 above) fed
`Worker::getEventLoop()` straight into `Process::start()` exactly as the
post-3.3 `RunHandler` did. Under the OLD stack (Workerman **v4.1.10** +
react/child-process **v0.6.5**) this would likewise have thrown a `TypeError`
— no React-loop adapter was ever configured historically. It was never caught
because **no pre-existing test ever exercised `Process::start()` for real**
(the only child-process coverage tested `RunListHandler` with a null/mocked
process), which is why it slipped through in production until now. **Real
value-add discovery of this cleanup, not a program-introduced regression.**

### 10.4 The fix — `ReactLoopBridge` + `ReactLoopBridgeTimer`

Two new files under `src/`, plus a one-line change at each of exactly two call
sites. All other logic (frame shapes, exit-code propagation, tempfile handling)
is untouched.

| File | Class | Role |
|---|---|---|
| `src/ReactLoopBridge.php` | `MyAdmin\VpsHost\ReactLoopBridge implements React\EventLoop\LoopInterface` | Delegates every required `LoopInterface` operation to the worker's live `Workerman\Events\EventInterface`: `addReadStream`/`removeReadStream` → `onReadable`/`offReadable`; `addWriteStream`/`removeWriteStream` → `onWritable`/`offWritable`; `addTimer` → `delay`; `addPeriodicTimer` → `repeat`; `cancelTimer` → `offDelay`/`offRepeat`; `futureTick` → `delay(0.0, …)`; `addSignal`/`removeSignal` → `onSignal`/`offSignal`. `run()`/`stop()` are **intentional no-ops** — Workerman owns/drives the loop lifecycle; stopping it would kill the worker. |
| `src/ReactLoopBridgeTimer.php` | `MyAdmin\VpsHost\ReactLoopBridgeTimer implements React\EventLoop\TimerInterface` | Wraps a Workerman integer timer id (public `$workermanTimerId`) so it can be handed back as the `TimerInterface` handle `React\ChildProcess\Process` expects when it internally cancels its own exit-poll timer via `$loop->cancelTimer($timer)`. |

**Public accessor:** the bridge is obtained via the static
**`ReactLoopBridge::instance()`** (NOT a constructor call at the call site). It
memoizes a single instance and rebuilds it if `Worker::getEventLoop()` returns a
different loop object; only valid inside a started worker (after
`onWorkerStart`).

**Applied at exactly two call sites** (one functional line each):

- `src/Handlers/RunHandler.php` (~line 47): `$loop =
  \MyAdmin\VpsHost\ReactLoopBridge::instance();` then
  `$process->start($loop)` — was `Worker::getEventLoop()`. An inline comment at
  the site documents why.
- `src/Tasks/vps_queue.php` (~line 14): `$loop =
  \MyAdmin\VpsHost\ReactLoopBridge::instance(); $browser = new
  React\Http\Browser($loop);` — the `React\Http\Browser` construction there had
  the same latent defect and gets the same bridge.

**Scope-census safety.** The bridge implements every `LoopInterface` method, but
a full method-census across the entire react vendor tree actually in use
confirmed only **8** of the interface's methods are ever called by any real
dependency — all correctly handled. Two minor, currently-**unreachable** gaps
are documented in the bridge's own docblock and left as-is (not exercised by any
current dependency): a `removeSignal` single-handler-per-signal limitation
(Workerman's `offSignal` drops the signal's handler wholesale), and `futureTick`
ordering (implemented as a zero-delay timer rather than a strict end-of-tick
queue).

### 10.5 Verification — real-worker empirical testing, independently re-derived twice

The fix was proven not by reasoning alone but by running a **real Workerman
worker (Select event-loop driver)** and observing live behavior — and this was
done **twice**: once by the investigating agent, then **independently** by a
separate Review Agent who wrote their **own** test rather than trusting the
first. Both confirmed:

- stdout/stderr/exit-code delivery happens **LIVE** (within ~0.01s of the
  event, not deferred to shutdown) across many real child-process spawns and
  worker cycles;
- a **pipes-closed-while-still-running** edge case correctly exercises the
  bridge's periodic exit-poll-timer path and still emits the correct exit code;
- a **SIGTERM'd child** correctly reports `code=NULL, term=15` through the
  bridge;
- all 12 `LoopInterface` methods were checked against Workerman's actual
  `EventInterface` and confirmed semantically correct.

**Test file:** `tests/phpunit/ReactLoopBridgeTest.php`.
**Suite counts:** **59 tests / 80,330 assertions, green** (was 56/80,318 before
this fix) — re-confirmed on-disk (`PHPUnit 11.5.56`, PHP 8.3.6).

### 10.6 Flag for step 3.6 (PTY) — reuse `ReactLoopBridge`, never repeat this mistake

**Directly relevant to step 3.6 (PTY session management), which will likely need
similar subprocess/stream machinery:** any future React component that needs an
event loop while running inside a Workerman worker process **MUST** use
`ReactLoopBridge::instance()`. Never pass `Worker::getEventLoop()` directly into
a React API expecting a `LoopInterface` (immediate `InvalidArgumentException` /
`TypeError`), and **never pass `null` either** — that silently defers to React's
own un-ticked global loop and defers all stream/exit events to worker shutdown
(§10.2 mode 2). PTY work should route its React subprocess/stream needs through
the same bridge rather than re-introducing this latent defect.

### 10.7 Documentation touch-ups made in this pass

Class-level and public-method PHPDoc was **already present and thorough** on
both `ReactLoopBridge` (documenting the v4→v5 incompatibility, the null-fallback
trap, the run/stop no-op rationale, and the `addSignal` single-handler caveat)
and `ReactLoopBridgeTimer` (documenting the Workerman-timer-id wrapping and why
`Process` needs the `TimerInterface` handle). This pass found nothing genuinely
missing, so **no docblock additions were required** — only this BASELINE §10
prose was added. **No application logic was changed** in this pass, and `php -l`
is clean on every file touched (`docs/BASELINE.md` prose only;
`src/ReactLoopBridge.php`, `src/ReactLoopBridgeTimer.php`,
`src/Handlers/RunHandler.php`, `src/Tasks/vps_queue.php` all re-linted clean and
unmodified by this documentation pass).

## 11. Step 3.5 — Map handlers to v1 protocol (dual-running) COMPLETE (applied 2026-07-06, reviewed CLEAN ×2 + Test stage)

Step 3.5 is **done**. It layered the frozen `docs/PROTOCOL_V1.md` wire protocol
onto the step-3.3 handler architecture **without disturbing a single byte of the
legacy path**: with no local token file present the agent is byte-identical to
the pre-3.5 agent (legacy `{type:"login"}` on connect, legacy frame shapes
everywhere, zero v1 frames ever constructed); a token file flips it into v1
mode. Two Review→Fix rounds passed and a Test stage added committed unit
coverage. Everything below was read directly from the on-disk `src/…` files, not
carried over from the plan.

> **History note.** This step's *first* implementation attempt was interrupted by
> a session/rate-limit cutoff and left `src/Handlers/RunHandler.php`
> runtime-broken; that was repaired and, in the aftermath, the significant
> pre-existing React-vs-Workerman-v5 event-loop bug of **§10** was found and
> fixed. The actual v1-mapping work documented here was then re-implemented from
> scratch on top of that clean, §10-repaired foundation. `src/V1Envelope.php` is
> the one artifact that **survived** the interrupted attempt (see §10's
> C5-incident note): it was independently re-verified correct against
> PROTOCOL_V1 §1 and adopted unmodified as the envelope foundation.

### 11.1 The two-lane dispatch architecture (v1 alongside legacy)

`Agent::onMessage()` `json_decode`s each frame and routes it down exactly one of
two lanes, chosen by an **envelope-shape probe that runs before anything else**:

| Probe | Lane | Handler registry |
|---|---|---|
| `V1Envelope::isV1($data)` true (frame carries `v:1` + `op`/`re`, never `type`) | **v1 lane** → `Agent->v1->onEnvelope()` | replies resolve `V1Client`'s pending-id map; requests → `V1MessageDispatcher` (op → handler) |
| otherwise, `is_array && isset($data['type'])` | **legacy lane** → `MessageDispatcher` (unchanged step-3.3 `type` → handler) | the 11 legacy handlers of §8 |
| neither (malformed / non-JSON) | the §8.4 graceful `"Unhandled Mesage Type "` log + `return` | — |

The two dispatchers are deliberate mirror images: `V1MessageDispatcher` is the
exact v1 counterpart of the legacy `MessageDispatcher` — same explicit
typed-registry design (constructor-built `op => handler` map, `register()` /
`getHandlers()`, no dynamic-property/`__call` magic), differing only in that a
missing op answers `{ok:false, error:{code:"unknown_op"}}` per §1 (legacy's
default logs the byte-preserved "Unhandled Mesage Type" typo instead). Because a
v1 envelope can **never** be a valid legacy frame (it has no `type`) and a legacy
frame can never be a valid v1 envelope (`V1Envelope::isRequest/isReply` demand
`v===1` plus `op`/`re`), the probe partitions traffic cleanly and the legacy
dispatch path is left **byte-unchanged** — v1 detection is purely additive.

`V1MessageDispatcher` runs `V1Envelope::decodeData()` on the envelope **before**
calling a handler, so every op handler always sees a plain-array `data` (an
`enc:"gzip"` payload is transparently base64+gzuncompress-decoded, and a
malformed one is answered `bad_request` instead of crashing the worker — same
long-running-service robustness posture as §8.4).

### 11.2 The `TokenStore` dual-running gate — why file-presence is the right mechanism

The whole v1 stack is gated by one predicate: **`TokenStore::hasToken()`** — is
there a readable, non-empty local token file (default `/etc/datacentered/agent_token`,
mode 0600, overridable via `config.ini [v1] token_file` for harnesses)?

- **No token → guaranteed pure-legacy.** `V1Client::enabled()` returns false, so
  `Agent::onConnect()` sends the legacy `{type:"login"}` and every telemetry
  sender stays legacy-shaped. The agent **never constructs or expects a v1 frame
  on its own** — it is byte-identical to the pre-3.5 agent. (The one exception is
  *inbound* v1 detection, which stays live even token-less, solely so the
  `config.token` bootstrap push can be received over the legacy-authenticated
  link — see §11.5.)
- **Token present → v1.** `onConnect` sends `auth.hello{role:"host", host_id,
  token, agent_version, virt_type, module}` as the mandatory first frame
  (§2.1/§3); `auth.welcome` flips `V1Client::$authed` and starts the timers (the
  v1 replacement for the legacy login-ack → `LoginHandler` → `setupTimers`
  trigger), honoring any hub-supplied per-timer interval overrides.

**Why this is the natural/correct gate (reviewed & approved).** It mirrors the
hub's own "Flag-A-dormant-by-default" philosophy — the new protocol ships inert
and only activates on an explicit operator/hub action — **without** needing to
replicate the hub's GlobalData-backed two-flag mechanism on the agent side. On
the agent, the token file *is* the activation signal: a host cannot speak v1
until it has been issued a token, and issuing the token (via `config.token`,
§11.5) is itself the deliberate flip. One piece of state, atomically written,
0600, that doubles as both "can I authenticate?" and "am I in v1 mode?" — no
second flag to keep in sync, and the file's mere presence/absence is a
crash-proof, restart-surviving gate. `TokenStore::hasToken()` was independently
verified airtight by Review across missing / empty / whitespace-only /
unreadable / corrupted-JSON file edge cases (all → treated as no-token → legacy).

### 11.3 The 8 v1 handlers — extends-vs-delegates, zero duplicated business logic

`V1MessageDispatcher` registers 8 ops. Every one of them reuses the corresponding
**unchanged** legacy handler rather than re-implementing its logic — via one of
two patterns, chosen by whether the v1 wire shape needs custom frame builders:

| v1 op (§) | v1 handler | reuse pattern | reused legacy code |
|---|---|---|---|
| `ping` (§2.1) | `V1\PingHandler` | standalone | — (emits a `re`-correlated pong, `data:{}` not `[]`) |
| `cmd.exec` (§2.2) | `V1\CmdExecHandler` | **extends** `RunHandler` | inherits the entire spawn/stream core (temp-file for multi-line cmds, COLUMNS/LINES env, `ReactLoopBridge::instance()` per §10, `$agent->running` bookkeeping, `update_after` chaining, temp-file cleanup); overrides ONLY `sendOutputFrame`/`sendExitFrame` to emit `cmd.output`/`cmd.exit` envelopes |
| `cmd.stdin` (§2.2) | `V1\CmdStdinHandler` | standalone | same `process->stdin->write()` the legacy `RunningHandler` used |
| `cmd.kill` (§2.2) | `V1\CmdKillHandler` | **delegates to** UNCHANGED `StopRunHandler` | close-pipes + SIGKILL — see §11.4 |
| `config.maps` (§2.6) | `V1\ConfigMapsHandler` | **delegates to** UNCHANGED `GetMapHandler` | byte-identical map-file writes + ebtables/xinetd-vnc rebuild + `vps_get_list` chain (wraps payload back into the legacy `{type:"get_map", content}` shape) |
| `config.token` (AUTH_DESIGN §3) | `V1\ConfigTokenHandler` | standalone | persists via `TokenStore`, acks a fingerprint — see §11.5 |
| `agent.update` (§2.8) | `V1\AgentUpdateHandler` | **extends** `SelfUpdateHandler` | inherits `runUpdate()` (bundled `update.sh` or url-override download, optional reload); only the field handling is v1 (one `rand(1,jitter_max)` splay replacing the legacy double `rand(1,30)`, `url`/`restart` from the envelope) |
| `telemetry.sysinfo` (§2.5) | `V1\TelemetrySysinfoHandler` | **extends** `PhpsysinfoHandler` | inherits the TaskWorker phpsysinfo round-trip (`runSysinfo`); wraps the result in the frozen `enc:"gzip"` reply envelope (§2.5) instead of the legacy inline `base64(gzcompress())` field |

**The explicit rationale (why this pattern, not copy-paste):** every unit of real
behavior — process spawning, map-file byte layout, the update/reload mechanics,
the phpsysinfo round-trip, the kill sequence — lives in **exactly one place**,
the legacy handler. The v1 handlers only translate wire shapes. This guarantees
**zero duplicated business logic** and therefore **zero risk of the v1 and legacy
paths silently drifting apart** over time: a future fix to (say) map-file writing
lands once in `GetMapHandler` and is automatically correct on both protocols. To
make the `extends` cases possible, `RunHandler` / `SelfUpdateHandler` /
`PhpsysinfoHandler` had their reusable cores mechanically extracted into
`protected` methods; each legacy `handle()`'s external behavior was verified
**byte-for-byte unchanged** by two independent Review passes (diffed line-by-line
against the pre-3.5 version).

### 11.4 The Phase 2 carried-forward requirement — legacy `stop_run` kills v1 runs

**The single most important behavioral requirement of this step.** The hub's
`onClose` admin-disconnect sweep still emits a legacy `{type:"stop_run", id}`
frame **even for runs that were started via the v1 `cmd.exec` op** — a Phase 2
requirement carried forward into Phase 3. The agent must therefore honor legacy
`stop_run` against a v1-originated run.

This is satisfied structurally by two facts working together:
1. `CmdExecHandler` registers its run in `$agent->running` under the same
   `run_id` key the legacy `$agent->running` map uses (it maps the frozen §2.2
   fields onto the legacy-internal shape `RunHandler::handle()` consumes, with
   `id` = `run_id`). So the legacy `StopRunHandler` / `RunningHandler` /
   `RunListHandler` all address v1-originated runs transparently — same key.
2. The new `cmd.kill` op is **ADDITIONAL** to legacy `stop_run`, not a
   replacement: `stop_run` stays registered in the legacy `MessageDispatcher`,
   and `CmdKillHandler` itself **delegates to the UNCHANGED `StopRunHandler`** so
   the kill sequence (close pipes + `terminate(SIGKILL)`) is single-sourced. Both
   ops hit the same `$agent->running` entry.

Confirmed not by reasoning alone but by a **real empirical smoke test** (against a
mock v1 hub, independently re-derived TWICE — once by the implementing Step
Agent, once by the first Review Agent with its own separate harness): a legacy
`stop_run` frame genuinely killed a still-streaming v1-originated `cmd.exec` run.

### 11.5 Log-redaction hardening — the gzip-`config.token` gap and its fix

`config.token` (AUTH_DESIGN §3) is how the hub bootstraps/rotates a bearer token:
it pushes `{host_id, token, issued_at}`; `ConfigTokenHandler` persists it via
`TokenStore` (0600, atomic) and acks with a `sha256`-prefix **fingerprint** only
— the plaintext token is never logged and never leaves the 0600 file / one WSS
frame / hub memory. Notably this op is accepted **while the connection is still
legacy-authenticated** (that is the bootstrap: a token-less host receives its
first token over the legacy link; only its *next* connect performs the v1
`auth.hello` handshake — the current connection's mode is never switched
mid-stream).

**The gap Review caught (currently latent, but real).** `Agent::onMessage()`
echoes every inbound frame to the log. A plaintext `"token"` field was already
value-redacted — but a `config.token` frame sent with `enc:"gzip"` was being
logged as its **raw base64 blob**, which, while not literally plaintext, is
**trivially reversible** (`base64_decode` + `gzuncompress` recovers the token).
It is latent only because the hub has not implemented the `config.token` push
yet — but the agent already accepts it, so the exposure is real the moment the
hub does.

**The fix (`Agent::redactFrameForLog()`, added in a follow-up Fix round).** For
logging **only** (the frame handed to the dispatchers is never modified),
whenever a frame's `op` is `config.token`, the **entire `data` field** is
replaced with the literal `"[REDACTED]"` **regardless of encoding** — rather than
attempting to selectively decode-then-re-redact inside an opaque gzip blob. A
cheap `config.token` substring guard keeps the common path off the `json_decode`.
A second independent Review pass added **12 further adversarial test cases**
(whitespace / key-reordering, coincidental substring matches, duplicate JSON
keys, case variants, `\u`-escaped JSON) and confirmed the fix robust; one purely
theoretical, unreachable-in-practice false-negative was noted and explicitly
judged not worth fixing. **Why it matters even while latent:** a bearer token is
the agent's whole credential — logging a reversible form of it (even to a local
file) is a credential-in-logs exposure, and closing it *before* the hub side goes
live is exactly when it is cheap and safe to close.

### 11.6 Test / smoke-test coverage — what is live-verified vs structural-only

**Live/runtime smoke-tested** (mock v1 hub, re-derived twice — see §11.4):
`auth.hello`→`auth.welcome`; `cmd.exec`→`cmd.output`(stdout+stderr)→`cmd.exit`
(exit code propagated verbatim); **legacy `stop_run` killing a v1-originated run**
(§11.4); `telemetry.host` / `telemetry.inventory`(gzip) / `telemetry.host_extra`;
`config.maps` pull+push **byte-identical** (`cmp`-verified against legacy
`GetMapHandler` output); `ping`/`pong`; `config.token` rotation + redaction; and a
**legacy-mode control run (no token file) emitting ZERO v1 frames with map-file
bytes identical to pure legacy operation** — the dual-running invariant proven
empirically.

**Structurally sound but NOT yet live/runtime-exercised** (per the implementing
agent): `cmd.stdin`, `agent.update`, `telemetry.sysinfo`, and the `queue.*` family.

**Pre-existing queue-timer gap (NOT introduced by this step).** The `queue.*`
senders (`V1Client::queuePull` / `queueProvision`, dispatched from
`Agent::vps_queue_timer()`) are wired but effectively dormant because
**`Agent::setupTimers()` never registers `vps_queue_timer` at all** — it arms only
`vps_update_info`, `vps_get_traffic`, `vps_get_list`, and `check_interval`. This
scheduling gap predates step 3.5 (the timer was never scheduled in the legacy
agent either) and is out of scope here.

**Committed unit coverage.** A Test stage added four new test files under
`tests/phpunit/`:
- `V1EnvelopeTest.php` — the `V1Envelope` §1 detection predicates (valid
  request/reply shapes, legacy frames never matching, malformed/partial shapes
  rejected), the request/reply/error builders' exact field shapes, the
  `enc:"gzip"` round-trip and its malformed failure modes, RFC-4122 uuid
  generation, and the by-design gzip-marker/array-data classification contract
  (see §11.8);
- `TokenStoreTest.php` — the dual-running-gate predicate across the §11.2 edge
  cases (missing / empty / whitespace-only / unreadable / corrupted files),
  0600 atomic persistence, and the bare-token fallback;
- `V1MessageDispatcherTest.php` — the op registry, `unknown_op` error replies,
  and the pre-dispatch `decodeData()` gzip-decode guard (malformed gzip →
  `bad_request`);
- `RedactFrameForLogTest.php` — the §11.5 `config.token` log-redaction fix,
  including the adversarial cases (whitespace / key-reordering, coincidental
  substring matches, duplicate JSON keys, case variants, `\u`-escaped JSON).

Suite total is now **143 tests / 80,846 assertions, zero failures** (was
59/80,330 at §10). **See §11.8 for a classification-contract question that was
adjudicated as by-design and is now locked in by a dedicated test.**

### 11.7 Two pre-existing bugs found — deliberately left unfixed (out of scope)

Both are genuine, pre-date this step, and are correctly **not** this
protocol-mapping step's job to fix:

1. **`src/Tasks/vps_get_cpu.php:23` returns an undefined `$data`** — the CPU stats
   it computes are accumulated into `$cpu`, but the function `return $data;`
   returns an always-`null` variable. The legacy path silently forwards the
   string `"null"`; the new v1 `telemetry.cpu` sending branch correctly **detects
   this and skips sending** until the underlying task bug is fixed elsewhere (not
   by patching `vps_get_cpu.php` here).
2. **`SelfUpdateHandler::runUpdate()` legacy branch does
   `exec(file_get_contents(update.sh))`** — i.e. it runs the *entire* file's
   contents as a single shell command line (a latent hazard if `update.sh` ever
   becomes multi-line in a way that does not work as one exec'd string).
   Preserved **verbatim** for exact legacy parity; hardening it is not in scope
   for a protocol-mapping step.

### 11.8 The gzip-marker/array-data classification leniency — adjudicated BY-DESIGN (resolved, test-locked)

**Status: RESOLVED — intentional design, locked in by a dedicated test.** A frame
like `{v:1, id:'a', op:'ping', ts:1, enc:'gzip', data:[]}` (gzip marker but
**array** `data`) *does* pass `V1Envelope::isRequest()`, because its final clause
`is_array($data['data']) || (enc==='gzip' && is_string($data['data']))`
short-circuits on the first term: `data` is `[]` (an array), so the check passes
before the enc/string arm is ever consulted. The same behavior exists in
`isReply()`'s ok:true arm. This was initially flagged as a possible bug, but was
adjudicated as the **correct, intentional layering**:

- `isRequest()`/`isReply()` are cheap **structural detection** predicates at the
  dispatch-routing layer — their job is only to decide "is this a v1 frame at
  all?" so traffic can be lane-partitioned (§11.1), not to validate payload
  encoding consistency.
- **Strict enc/type enforcement lives at the actual decode layer**:
  `V1MessageDispatcher` runs `V1Envelope::decodeData()` before any handler is
  called, and `decodeData()` *does* reject a gzip envelope whose `data` is not a
  decodable string — so a gzip-marker/array-data frame is still answered
  `bad_request` at dispatch time. Nothing malformed ever reaches a handler.

The contract is locked in by
`V1EnvelopeTest::testGzipMarkerWithArrayDataStillDetectsAsRequestByDesign`,
whose docblock spells out the by-design layering (structural detection at
routing, strict enforcement at decode), so a future pass cannot "fix" the
leniency without consciously breaking the test. The full suite is green with
this contract in place (**143 tests / 80,846 assertions, zero failures** — see
§11.6).

### 11.9 Documentation touch-ups made in this pass

Class-level and method-level PHPDoc was **already present and thorough** across
every new v1 class — `V1Envelope` (§1 detector/builder/decoder semantics, uuid),
`TokenStore` (the dual-running-gate role, 0600 atomic save, bare-token fallback,
never-log contract), `V1Client` (dual-running gate, the auth handshake, reply
correlation, each `queue.*` sender), `V1MessageDispatcher` (the op registry +
`unknown_op` + gzip-decode guard), `V1HandlerInterface`, and all 8 V1 handler
classes (each documenting its op, its extends/delegates reuse target, and the E1
byte-compat / verbatim-exit-code invariants) — as were the extracted
`protected` frame-builder/mechanics methods on `RunHandler` /
`SelfUpdateHandler` / `PhpsysinfoHandler` and the new `Agent::redactFrameForLog()`
/ `onConnect` / `onMessage` v1 branches. This pass found **nothing genuinely thin
or missing**, so **no docblock additions were required** — only this BASELINE §11
prose was added. **No application logic was changed** in this pass, and `php -l`
is clean on every source file inspected (`src/TokenStore.php`, `src/V1Client.php`,
`src/V1MessageDispatcher.php`, `src/V1Envelope.php`, `src/Agent.php`,
`src/Handlers/RunHandler.php`, `src/Handlers/SelfUpdateHandler.php`,
`src/Handlers/PhpsysinfoHandler.php`, and all 9 files under
`src/Handlers/V1/`); only `docs/BASELINE.md` prose was written.

## 12. Step 3.6 — PTYSession/PTYPool + PTYHandler (real terminals via pty.*) COMPLETE (applied 2026-07-06, reviewed CLEAN ×2 + Fix + Test stage)

Step 3.6 is **done**. It adds agent-side support for the four v1 pseudo-terminal
ops of `PROTOCOL_V1.md` §2.3 — `pty.open` / `pty.data` / `pty.resize` /
`pty.close` — so a hub-relayed interactive session gets a **real kernel pty**
(a `/dev/pts/N` slave with genuine terminal semantics), not the plain-stdio-pipe
approximation the legacy `run`/`interact:true` path offered. It also closes a
Phase 2 carried-forward cleanup item, **[2.4 LOW-1]** ("No pty cleanup… Add a pty
reaper / disconnect cleanup in Phase 3"). The v1 dispatcher now registers **12
ops** (up from 8 after §11). Everything below was read directly from the on-disk
`src/…` files, not carried over from the plan. As with every prior step, the
legacy path is left **byte-unchanged**: these are additive v1 ops, and with no
token file present none of this code ever runs.

### 12.1 The architecture — `PTYSession` + `PTYPool` + four thin handlers

The step splits cleanly into OS-state objects and wire-shape translators,
mirroring the §11 "business logic in one place, handlers only translate frames"
discipline:

| Layer | Class(es) | Responsibility |
|---|---|---|
| One open pty | `src/PTYSession.php` | `proc_open()` with `pty` descriptor type → real `/dev/pts/N`; output streaming on Workerman's native event loop; write; **real** resize; alive-check; idempotent terminate |
| Registry + reaper | `src/PTYPool.php` | in-process `pty_id → PTYSession` map (`open/get/all/remove/count`), plus `closeAll()` and `reap()` — the [2.4 LOW-1] cleanup |
| Wire translation | `src/Handlers/V1/Pty{Open,Data,Resize,Close}Handler.php` | map the exact §2.3 field contract (base64 `pty.data`, `pty_id` correlation, `code` on close) onto the pool/session API |
| Wiring | `src/Agent.php`, `src/V1MessageDispatcher.php` | `$agent->ptys` (PTYPool) property; `onClose()`→`closeAll()`; 60s `pty_reap` timer; 4 op registrations |

**`PTYSession` — one real pty.** `proc_open()` is given `['pty']` for fds 0/1/2,
which allocates a genuine kernel pty pair (master + `/dev/pts/N` slave) rather
than three anonymous pipes. Because a pty **fuses** the child's stdout and stderr
onto the same slave device, there is only ONE read-side stream to service
(`$pipes[1]`) — there is no separate stderr to multiplex, exactly as a real
terminal behaves. An empty `command` spawns a login shell (`$SHELL -l`, falling
back to `/bin/bash -l`); a non-empty `command` execs that command inside the pty.
`COLUMNS`/`LINES`/`TERM=xterm` plus `$_SERVER` are set as the child env; the
initial geometry is applied immediately at construction so a shell's very first
winsize ioctl query sees real dimensions.

**`PTYPool` — in-process, deliberately NOT GlobalData.** This is the one design
point worth stating loudly because it *looks* like it should mirror the hub's
`$global->running`/`$global->ptys`. It must not. A pty child is **agent-local OS
state** — a real pid and open fd resources on THIS host — with no cross-process
meaning: another process cannot `read()`/`write()` this process's pipe resources,
so a GlobalData entry would add a network round-trip for zero coordination
benefit and would not reflect real fd/pid ownership anyway. There is exactly one
agent process per host holding these, so a plain in-process array is both
sufficient and correct. (Contrast §11's hub-side coordination state, which is
genuinely cross-worker and correctly lives in GlobalData.)

### 12.2 The resize mechanism — `stty -F <slave>` is a real `TIOCSWINSZ`, not a cosmetic no-op

This host has **no `ext-pty`**, and PHP userland exposes no `ioctl(TIOCSWINSZ)`
primitive; the installed extensions (`ext-posix`/`ext-pcntl`/`ext-ffi`) do not
give a direct ioctl without a C shim. So a naive implementation would either
leave resize unimplemented or fake it by only updating `COLUMNS`/`LINES` (which
does **not** deliver `SIGWINCH` and leaves ncurses/readline querying the real,
stale winsize). Neither was acceptable.

The chosen mechanism is a genuine one:

1. Resolve the **real** slave device path via `readlink("/proc/{pid}/fd/0")`.
   This is the crux: `posix_ttyname()` on proc_open's own master-side descriptor
   reports `/dev/ptmx` — **useless** for resizing, because that is the multiplexor
   clone device, not the child's actual `/dev/pts/N`. The `/proc/{pid}/fd/0`
   symlink is what resolves to the real slave.
2. Shell out to the system `stty` binary: `stty -F <slave> rows R cols C`. This
   performs a real kernel-side `TIOCSWINSZ` ioctl on the slave and delivers
   `SIGWINCH` to the foreground process group.

`resize()` returns a real boolean (true only if the ioctl was actually applied),
not an unconditional success. This was **empirically verified — twice**: the
implementer confirmed that a child's own `stty size` reflects an
externally-applied geometry immediately after the call returns, and the Test
Agent independently re-verified it by reading the geometry from the actual slave
(not merely trusting the boolean return). It is a real resize.

### 12.3 The reaper — closing Phase 2 item [2.4 LOW-1], with two independent trigger points

`PTYPool` provides two cleanup paths, both wired by `Agent`, which together
guarantee a pty child can never leak:

1. **`closeAll()` on hub disconnect.** `Agent::onClose()` calls
   `$this->ptys->closeAll()` (SIGKILL every open session) at the same point, and
   for the same reason, as the §9 `ReconnectManager` wiring — a dropped hub link
   must never leak a pty child past a reconnect.
2. **`reap()` on a 60s timer.** `Agent::setupTimers()` arms a new
   `addTimer('pty_reap', 60, [$this, 'pty_reap'])` (a fixed 60s cadence,
   deliberately **not** config-driven so the reaper is always live even on an
   unmodified `config.ini`). `reap()` sweeps for sessions whose child has already
   exited on its own — crashed, or exited without any `pty.close` ever arriving —
   and removes them, so a hub-side bug that forgets to send `pty.close` cannot
   grow this map unbounded. It is cheap and idempotent: one `proc_get_status()`
   per open session, nothing more.

These two paths are independent by design: `closeAll()` covers the "we lost the
hub" case, `reap()` covers the "child died but the hub never told us" case.
Together they directly satisfy [2.4 LOW-1].

### 12.4 The ReactLoopBridge exemption — why §10's lesson genuinely does NOT apply here, verified twice

§10.6 flagged step 3.6 to "reuse `ReactLoopBridge`, never repeat this mistake."
On close inspection that flag turned out to be **inapplicable** to this
particular implementation — and this was not accepted on the code's own say-so;
it was independently re-derived by **two** Review Agents against the installed
Workerman v5.2.x vendor source.

The §10 bug was specifically about **actual React-API objects**
(`React\ChildProcess\Process` and similar) that need a
`React\EventLoop\LoopInterface` to drive them, and that break when handed
Workerman v5's native loop. `PTYSession` has **no React object anywhere in the
chain**: it is a raw `proc_open()` pty pair, and its output fd is polled via
`Worker::getEventLoop()->onReadable()` — Workerman's own native `EventInterface`
fd-registry API, which is exactly what core Workerman uses internally for its own
connections. There is no `LoopInterface` type contract to satisfy because there
is no React component. The bridge exists **solely** to adapt Workerman's loop to
React's `LoopInterface` type for genuine React objects; a plain stream fd polled
via `EventInterface::onReadable()` needs no such adaptation and is the correct,
sufficient primitive. Both reviewers confirmed this independently against vendor
source, not just from the class's own comment.

### 12.5 The four handlers — thin wire-shape translators, hub remains sole scope authority

Each handler does only frame translation; all OS behavior lives in
`PTYSession`/`PTYPool`. Unlike the §11.3 v1 ops (each of which `extends`/
`delegates` an **unchanged legacy handler**), all four pty handlers are
**standalone** — there was **no legacy pty equivalent** to reuse (the legacy
`run`/`interact:true` path is plain-pipe streaming, not a terminal), so this is
genuinely new capability rather than a wire-shape wrapper over pre-existing
behavior:

| v1 op (§2.3) | handler | shape |
|---|---|---|
| `pty.open` | `PtyOpenHandler` | validates `pty_id` (reject dup / empty → `bad_request`); enforces the §5 fail-closed scope gate (shell-mode → `forbidden` unless `data.elevated===true`, see below); opens the session, registers `watchStdout()` to stream child output as `pty.data` (base64) frames and to emit a self-inflicted `pty.close{code}` on EOF, replies `{pty_id}` |
| `pty.data` | `PtyDataHandler` | base64-decode → `session->write()`; **no reply**; unknown/closed `pty_id` silently dropped (data racing a close, §6) |
| `pty.resize` | `PtyResizeHandler` | `session->resize(cols, rows)` (the real §12.2 ioctl); **no reply**; unknown `pty_id` silently dropped |
| `pty.close` | `PtyCloseHandler` | `ptys->remove(pty_id, SIGTERM)`; **no reply**; idempotent — both sides may race to close the same session |

**Scope gating — hub primary, agent fail-closed secondary (§5 defense-in-depth).**
Per `PROTOCOL_V1` §5 the **hub** is the *primary* enforcement point for
`scope:"shell"` vs `scope:"command"` (conservative-denied server-side unless
`$_SESSION['pty_shell']===true`, which nothing currently sets), **and §5
ADDITIONALLY requires the agent to refuse a shell-scope open lacking an elevation
marker** — so a compromised/buggy hub cannot hand out login shells. That
agent-side gate is enforced in `PtyOpenHandler` and **fails closed** (see bug 2 in
§12.6): any open that would spawn a login shell — `scope === "shell"` **OR** an
empty/absent `command` — is refused with `forbidden` **unless** the frame carries
`data.elevated === true` (strict boolean check). §2.3 defines no dedicated wire
field for the elevation marker in the frozen spec, so `data.elevated === true` is
the agent's own narrowest-safe interpretation, defaulting to refuse when absent
(mirroring the hub's Phase-2-step-2.4 conservative-deny posture). A
`scope:"command"` open carrying a real non-empty `command` is unaffected and needs
no marker; the handlers otherwise faithfully honor whatever `command` the hub
relays (empty → login shell only if elevated; non-empty → exec it) and add no
privilege elevation of their own (no sudo, no role escalation).

**Client-supplied `env` is intentionally never merged** into the spawned child —
matching the hub's own documented "env dropped" decision (arbitrary
attacker-controlled `LD_PRELOAD`/`PATH`/`BASH_ENV` must not reach the host). Only
`COLUMNS`/`LINES`/`TERM` plus `$_SERVER` are set. Both Review and Test confirmed
this parity with the hub decision.

### 12.6 Three real bugs found and fixed (review → fix → re-review → test)

This step was subjected to the program's full C1 discipline (the Phase Agent never
edits code; every change goes through a fresh agent) — and unlike the mostly-clean
§10/§11 passes it surfaced **three genuine bugs**, each fixed before the step
closed. All three fixes below were re-read directly from the on-disk code for this
doc.

**Bug 1 — captured pid was the `/bin/sh` wrapper's, not the workload's.** The
original `PTYSession` built its `proc_open()` command as a bare string, which PHP
always runs as `/bin/sh -c <cmd>`. On this host `/bin/sh` is dash, which does
**not** tail-exec its last command under a pty — so the pid `proc_get_status()`
reported was the wrapper shell's, and `proc_terminate()`/SIGKILL against it would
**orphan the actual workload** (confirmed empirically via `ps`/`/proc`). Fixed by
building the command with an **`exec` prefix** so the shell replaces itself via
`execve()` and the captured pid **IS** the real process: empty/shell-mode →
`exec $SHELL -l` (fallback `exec /bin/bash -l`); a non-empty command →
`exec /bin/bash -c <escaped>` (nested through `bash` — not a bare `exec <command>`
— because `exec` before a compound list `a; b` would discard everything after the
first command, whereas bash tail-execs the final simple command of a `-c` string,
collapsing the wrapper pid onto the workload for compound commands too).
`PTYSession::__construct()` (~lines 103–124) carries the exec wrapper plus an
inline comment explaining the dash-vs-bash reasoning.

**Bug 2 — `pty.open` spawned an unrestricted login shell with zero scope
enforcement.** The original `PtyOpenHandler` opened a full login shell for any
`pty.open` with an empty/absent `command` and performed **no scope check at all** —
contradicting `PROTOCOL_V1.md` §5, which requires the agent to **ADDITIONALLY**
refuse a shell-scope open lacking an elevation marker (defense-in-depth behind the
hub's own conservative-deny). Fixed with a **fail-closed agent-side gate**
(`PtyOpenHandler` ~lines 56–66): any request where `scope === "shell"` **OR** the
command is blank is refused with `forbidden` **UNLESS** `data.elevated === true`
(strict boolean `!== true` check). No wire field for an elevation marker exists in
the frozen §2.3 spec, so `data.elevated === true` is the agent's own
narrowest-safe interpretation — defaulting to refuse when absent, analogous to the
hub's `$_SESSION['pty_shell']` conservative-deny stance from Phase 2 step 2.4. A
`scope:"command"` open with a real non-empty command is unaffected. (This is the
same gate now described in §12.5; an earlier draft of that subsection wrongly
stated no agent-side scope check existed — corrected here.)

**Bug 3 — `close()` blocked the event loop, and a docblock over-claimed a SIGKILL
escalation that did not exist.** The original `PTYSession::close()` went straight
to `proc_close()`, which **blocks in `wait4()`** until the child is reapable — a
SIGTERM-ignoring workload would hang the single event-loop thread indefinitely —
while a docblock falsely claimed a SIGKILL escalation was already in place. Fixed
with a bounded **non-blocking** teardown: after `proc_terminate($signal)` it calls
the new `waitForExit()` helper, which polls `proc_get_status()` with WNOHANG in
20ms sleeps for at most ~0.5s (never `wait4()`); if the child survives SIGTERM it
**escalates to SIGKILL** and waits again (bounded ~0.5s) before the trailing
`proc_close()`. Worst case is ~1s of synchronous wait, and only on an explicit
close/reap path (`pty.close`, `closeAll()`, or the 60s reaper) — never per frame.
The `close()` docblock (~lines 250–265) now describes the escalation that
**actually** exists, so the prior false claim is resolved (Docs-verified: the
docblock and the code now agree).

**Re-review + test.** A cold, fully independent second review (C1 cold-review rule
— run with **no framing about the prior work**) confirmed the three fixes and
re-derived the non-bugs: property naming (`$agent->ptys`) consistent at every call
site; `PTYSession::close()` safe when `watchStdout()` was never called (the
`offReadable()` deregister is guarded by the `$watching` flag, so it is skippable
in contexts with no running event loop, e.g. unit tests); reaper logic sound with
no iterate-while-mutate hazard; and the §12.4 ReactLoopBridge exemption genuine
(independently re-derived against vendor source). It found **one further LOW
issue** — `PtyCloseHandler` carried a redundant (harmless, dead) explicit
`offReadable()` call duplicating what `PTYSession::close()` already does safely —
which a **Fix Agent** removed along with its now-unused `use Workerman\Worker;`
import (this is why `PtyCloseHandler` today is a clean delegate to `ptys->remove()`
with no event-loop plumbing of its own). A fresh **Test Agent** then built the
real, un-mocked, `proc_open`-backed coverage that closed the "no dedicated
PTYSession/PTYPool/handler tests" gap (see §12.7) and corrected the stale
test-method name in `V1MessageDispatcherTest.php`.

### 12.7 Test coverage — real proc_open-backed, not mocked

The Test stage added three new test files under `tests/phpunit/`, all exercising
**real** pseudo-terminals (not test doubles):

- `PTYSessionTest.php` — real pty spawn; binary-safe round-trip through the pty;
  EOF handling on child exit; **real ioctl-verified resize** (geometry checked
  from the actual slave device, not just the boolean return); and real OS-level
  pid-death verification on `close()`.
- `PTYPoolTest.php` — both reaper paths against real children: `closeAll()`
  terminates everything, and `reap()` detects an externally-killed orphan while
  leaving a live sibling untouched.
- `PtyHandlersTest.php` — all four handlers' happy / edge / malformed-input paths
  (missing `pty_id`, duplicate open, bad base64, unknown-`pty_id` silent drops,
  etc.).

In addition, `V1MessageDispatcherTest.php` had a **stale test-method name**
corrected: `testRegistryHasExactlyEightOps` (whose body already correctly
asserted `assertCount(12, …)` after the pty ops were registered) was renamed to
`testRegistryHasExactlyTwelveOps` — an assertion-name accuracy fix only, no logic
change.

Final suite state, **re-run by this Docs Agent** (`vendor/bin/phpunit`, PHPUnit
11.5.56 / PHP 8.3.6): **191 tests / 81,089 assertions, zero failures**. The three
new PTY test files (`PTYSessionTest`, `PTYPoolTest`, `PtyHandlersTest`) plus the
`V1MessageDispatcher` 12-op count fix account for the PTY additions over the §11
baseline (143/80,846). `php -l` is clean on all touched/new files, and
`git -C /home/sites/datacentered status --porcelain` was re-confirmed
byte-identical to the pre-step-3.6 baseline (zero writes leaked into the separate
hub repo).

### 12.8 What's proven live vs structural (honesty section, matching §11.6)

**Proven with real (un-mocked) kernel-pty tests** (see §12.7): a pty actually
spawns and streams binary-safe output; a resize performs a real `TIOCSWINSZ`
verified from the slave device and delivers `SIGWINCH`; `close()` really kills the
child at the OS level; both reaper paths behave correctly against real children
(`closeAll()` terminates all; `reap()` removes an externally-killed orphan and
leaves a live sibling alone); and all four handlers handle their happy/edge/
malformed-input paths. The `pty.data`→stdin→`pty.data`-out round-trip is
exercised end-to-end through a real session.

**Structural but not yet exercised against a live v1 hub end-to-end** (per the
implementing/test agents): the full hub-relayed interactive session over an actual
WSS link — i.e. the complete `pty.open`→interactive-`pty.data`→`pty.resize`→
`pty.close` loop driven by a real hub rather than a test harness — has not been
smoke-tested against the real hub yet, because the hub's pty relay path itself is
still gated (scope conservative-denied unless `$_SESSION['pty_shell']===true`,
which nothing sets). The agent side is complete and unit-proven; the live
end-to-end proof waits on the hub enabling the path. This is analogous to §11.6's
"structurally sound but not yet live-exercised" set — the building blocks are each
independently verified, but the full cross-machine loop is not yet lit.

**Carried-forward / known non-blocking observation — `code: -1` on `pty.close`.**
`PTYSession::close()` returns `proc_close()`'s value, which the EOF-triggered
self-inflicted `pty.close` surfaces to the hub as its `code` field. Because of a
PHP `proc_open`/`proc_get_status` **reaping-order quirk** — the child may already
have been reaped by an earlier `proc_get_status()`/`waitForExit()` poll (§12.6 bug
3) by the time `proc_close()` runs — this numeric code **can come back `-1`** even
on a clean exit. This is a **cosmetic numeric-code issue only**: process death
itself is provably correct (verified at the OS level via `/proc` polling for both
the SIGTERM and SIGKILL paths, §12.7); only the reported exit *code* is sometimes
unavailable, not the teardown. It is **not** a bug to fix in this step and is left
as-is (non-blocking) — flagged here so a future reader does not mistake a `-1`
`code` on `pty.close` for a teardown-correctness problem.

### 12.9 Documentation touch-ups made in this pass

Class-level and method-level PHPDoc was **already present and thorough** across
every new file — `PTYSession` (the real-pty rationale vs plain pipes, the fused
stdout/stderr streaming model, the full `stty -F <slave>` resize-mechanism
decision with the `/proc/{pid}/fd/0`-vs-`posix_ttyname` explanation, the
ReactLoopBridge-exemption reasoning, and the scope/env non-elevation contract),
`PTYPool` (the in-process-not-GlobalData rationale and both reaper trigger
points with their [2.4 LOW-1] provenance), and all four `Pty*Handler` classes
(each documenting its §2.3 op, reply/no-reply shape, the silently-drop-on-race
posture, and the hub-is-sole-scope-authority / env-dropped invariants) — as were
the touched `Agent` members (`$ptys` property, `onClose()` closeAll, `pty_reap()`,
the `pty_reap` timer) and the `V1MessageDispatcher` op-table comments. This pass
was a **curation/accuracy review** of the class/method docblocks: every one was
re-read against the current code and found **accurate** — in particular the
`PTYSession::close()` docblock's SIGKILL-escalation description now matches the
code (the §12.6 bug-3 fix removed the old false claim) and `PtyOpenHandler`'s
docblock correctly documents the §12.6 bug-2 fail-closed scope gate — so **no
docblock/comment edits to any source file were required**. The edits this pass
made were confined to **this BASELINE §12 prose**, which corrected a **stale
earlier draft**: §12.5 no longer claims "no agent-side scope gate" (the fail-closed
gate is real — §12.6 bug 2), §12.6 now narrates the three real bugs found and
fixed (was a mis-stated review saga), the suite counts were refreshed to the
Docs-Agent-re-run **191 tests / 81,089 assertions**, and the `code: -1`
carried-forward note (§12.8) was added. **No application logic was changed** in
this pass; `php -l` is clean on every source file inspected (`src/PTYSession.php`,
`src/PTYPool.php`, the four `src/Handlers/V1/Pty*.php`, `src/Agent.php`,
`src/V1MessageDispatcher.php`), and only `docs/BASELINE.md` was written.

## 13. Step 3.7 — Keep boundaries (provirted + cron + HTTP fallback confirmed intact) COMPLETE (audit applied 2026-07-12, verified independently ×2 + Test stage)

Step 3.7 is **done**. Unlike §7–§12, it produced **zero code changes** — it is a
**pure confirmation/audit step**, which is the **expected and valid outcome per
the plan**, not a stalled or skipped step. Its purpose was to prove that the
Phase 3 WS-agent rebuild in this repo (`/home/sites/vps_host_server/workerman/`,
documented §7–§12) has **not disturbed and does not couple to** the **real
production path** that live clients actually depend on today. Everything below
was derived from **real `git diff` / `git blame` / `grep` / `php`-based
empirical checks**, not reasoning — and every claim was **independently
re-verified by 2 Review Agents plus a Test Agent**, each doing their own
git-diff/grep work rather than trusting the others.

### 13.0 Why this step is load-bearing for live production safety

An **owner clarification dated 2026-07-07** established a fact that reframes this
whole program's risk model: **the crontabbed `provirted` path — NOT the WS agent
— is what real clients actually depend on today.** The new WS host agent (§7–§12)
is **not yet in production**. Therefore any accidental edit, port collision, or
shared-state write that reached the legacy path would be a **live customer-facing
incident**, whereas bugs in the WS agent are still pre-production. That asymmetry
is exactly why 3.7 exists as its own gated step and why it was treated with **real
rigor (2 independent Review Agents + an empirical Test stage), not rubber-stamped**
— the whole point was to *empirically prove non-interference* rather than assert it.

### 13.1 What the real production path is

The live path, crontabbed **every minute**, is entirely **outside** this
`workerman/` rebuild:

- `/home/sites/vps_host_server/vps_cron.sh` and `qs_cron.sh` — the per-minute
  cron entry points.
- They invoke **`provirted.phar`**, which wraps `/home/sites/provirted`.
- Plus an **HTTP fallback** to the hub at
  `/home/sites/datacentered/Web/queue.php` on port **`55151`**.

### 13.2 Evidence — the production path is byte-unchanged (`git diff` empty)

`git diff` is **empty** on every file of the live path — `vps_cron.sh`,
`qs_cron.sh`, `provirted.phar`, and the hub's `Web/queue.php`. No byte of the
crontabbed production path was touched by any of steps 3.1–3.6. This was
re-run and re-confirmed by both Review Agents and the Test Agent.

### 13.3 The `.enable_workerman` flag-file gate — pre-existing (2018-era), NOT added by this program

The cron scripts contain a flag-file gate:

```sh
if [ -e $dir/.enable_workerman ]
```

When that flag file is present, the cron script switches **away from** the legacy
`provirted.phar`+HTTP path and instead **starts the new WS agent** (falling back
to the legacy path if the agent fails to start). Two facts confirmed empirically:

- **It is pre-existing.** `git blame` dates this gate to **2018** — it was **NOT
  introduced by this modernization program**. It is old scaffolding that predates
  Phase 3 entirely.
- **The flag file does NOT exist on this host.** Because `.enable_workerman` is
  absent, the `[ -e … ]` test is false, so **the legacy `provirted.phar`+HTTP
  path runs unconditionally today** and the WS-agent branch is **never reached**.
  The agent code path is dead on this host until someone deliberately creates
  that flag file (i.e. at actual Phase 3 cutover).

### 13.4 provirted's only hub cross-reference

`provirted`'s **only** cross-reference to the hub is the **pre-existing HTTP
calls to `queue.php:55151`**. That HTTP dependency is **unrelated to Phase 3** and
has nothing to do with the WS agent — it long predates this program and is part
of the legacy path itself.

### 13.5 The ONE coupling found — one-directional, inert, deploy-time coordination note (NOT a current breach)

The audit found exactly **one** point of contact between the new agent and the
legacy world. It is **one-directional (agent → legacy)** and **inert while the
agent is not running**:

- `workerman/src/Handlers/GetMapHandler.php:47` execs `provirted.phar vnc setup`.
- `workerman/src/Config/settings.php:28` holds a config string referencing
  `cpu_usage_updater.sh` / `cron.cpu_usage` — **the same state file the legacy
  cron path also writes.**

This is flagged as a **deploy-time coordination note for eventual Phase 3
cutover**, explicitly **NOT** a current boundary breach:

- **Why it's not a breach today:** with `.enable_workerman` absent (§13.3), the
  agent code path is **never reached**, so `GetMapHandler` never execs anything
  and the agent never writes `cron.cpu_usage`. Only the legacy cron writes it.
- **Why it needs coordination at cutover:** *if* the agent and the legacy cron
  ever ran **simultaneously on the same host** with `.enable_workerman` set,
  **both would write `cron.cpu_usage`** — a shared-state collision. That must be
  coordinated at the **actual cutover moment** (the cron gate is meant to make
  this either/or, not both). Recorded here so the future cutover engineer sees it
  before flipping the flag.

### 13.6 Port / socket independence (zero overlap)

A port/socket census confirmed the two paths share **nothing**:

| | WS agent (this repo) | Legacy cron/provirted path |
|---|---|---|
| **Listens** | `Text://127.0.0.1:55552` (local task worker), `127.0.0.1:55553` (local GlobalData) | — (cron is not a listener) |
| **Connects out** | hub `7271` / `7272` (WS/WSS) | hub `55151` (HTTP fallback) |
| **Local state files** | (its own) | `cron.output`, `cron.cmd`, `.cron.age`, `cron.psoutput`, `/dev/shm/lock` |

The agent's local bind ports (`55552`/`55553`) are **local-only** and do **not**
overlap the legacy `:55151` HTTP port, and the two use **disjoint** local state
files. No socket, port, or (with the flag absent) state-file contention exists.

### 13.7 provirted.phar integrity confirmed

`provirted.phar` was verified as a healthy, unmodified artifact:

- **Valid PHAR**, **1249 entries**.
- **Byte-identical git hash to HEAD** (matches §13.2's empty diff).
- Its **`list` subcommand runs cleanly** — the phar is not merely present but
  actually executable/functional.

### 13.8 Conclusion — zero code changes needed, and that is the correct outcome

No code changes were made in step 3.7 anywhere — **not in this `workerman/` repo,
and not in the read-only hub repo `/home/sites/datacentered`** (confirmed
`git -C /home/sites/datacentered status --porcelain` shows **only pre-existing
untracked files**, none authored by this step). The live production path is
byte-unchanged, the WS agent has **zero coupling** to it while `.enable_workerman`
is absent, ports/sockets/state files are disjoint, and `provirted.phar` is intact.
The single agent→legacy contact point (§13.5) is a **future-cutover coordination
note**, not a present defect. This is a **pure audit/confirmation step whose
correct result is "nothing to change"** — verified with real empirical tooling by
**2 independent Review Agents + a Test Agent**, deliberately not rubber-stamped
given its §13.0 live-production stakes. The only file written this pass was
`docs/BASELINE.md` (this §13 and the header line).

> **See also — `docs/ROLLOUT_PLAN.md` (reference only).** A companion
> **reference document** works through *how* a staged per-host cutover to the WS
> agent could be executed against the real mechanisms this §13 confirmed (the
> `.enable_workerman` gate §13.3, port/state-file independence §13.6, the
> `cron.cpu_usage` coupling §13.5, and the fail-back path §13.1). Per the §13.0
> owner clarification it is **not an active roadmap and has no owner or
> timeline** — indefinite non-cutover is an accepted end state. It exists so the
> staging, monitoring, and rollback thinking is already done *if* a cutover is
> ever wanted; do not read its wave numbering as a schedule.
