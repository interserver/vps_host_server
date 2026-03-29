---
name: workerman-event-handler
description: Adds a new Workerman event handler or background task following the closure patterns in workerman/src/Events/ and workerman/src/Tasks/. Handles timer registration in setupTimers.php, message routing in onMessage.php, and closure-based stdObject methods. Use when asked to 'add a workerman handler', 'new event', 'background task', or modify workerman/src/. Do NOT use for worker process config (workerman/src/Workers/).
---
# workerman-event-handler

## Critical

- Every event and task file **must** `return function ($stdObject, ...) { ... };` — the closure is auto-loaded and stored as a property on the `stdObject` instance.
- `$stdObject` is **always the first argument** of every closure (injected automatically by `stdObject::__call`). Never omit it.
- Never use `echo` or `print`. Always log with `Worker::safeEcho("message" . PHP_EOL)`.
- Do not add a PHP namespace. Files are loaded with `include` via `glob`, not autoloaded.
- Never touch `workerman/src/Workers/` — that is worker process config, not event/task logic.

## Instructions

### 1. Create the task or event file

**For a background task** → `workerman/src/Tasks/<task_name>.php`  
**For a Workerman lifecycle event** → `workerman/src/Events/<event_name>.php`

Minimal task template (mirror `workerman/src/Tasks/vps_queue.php`):
```php
<?php
use Workerman\Worker;

return function ($stdObject) {
    Worker::safeEcho("Running <task_name>" . PHP_EOL);
    // task logic here
};
```

Minimal event template (mirror `workerman/src/Events/onConnect.php`):
```php
<?php
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

return function ($stdObject, AsyncTcpConnection $conn) {
    // event logic here
};
```

Verify: the file ends with `return function ...` — not a class, not a plain script.

### 2. Register a timer (if the task runs on an interval)

**Step 2a** — Add the interval to `workerman/src/Config/settings.php` inside the `'timers'` array:
```php
'timers' => array(
    // existing entries ...
    '<task_name>' => 300,   // interval in seconds
),
```

**Step 2b** — Register the timer in `workerman/src/Events/setupTimers.php`:
```php
$stdObject->addTimer('<task_name>');
```
For a custom callback: `$stdObject->addTimer('<label>', $interval, array($stdObject, '<task_name>'));`

Verify: the key in `settings.php['timers']` exactly matches the string passed to `addTimer()`.

### 3. Add a message type handler (if triggered over WebSocket)

In `workerman/src/Events/onMessage.php`, add a `case` inside the `switch ($data['type'])` block:
```php
case '<new_type>':
    $json = array(
        'type' => '<new_type>_response',
        'result' => $someValue,
    );
    $conn->send(json_encode($json));
    break;
```

Verify: the new `case` is placed before `default:` and ends with `break;`.

### 4. Expose the handler as a callable method on `$stdObject`

Task files in `workerman/src/Tasks/` are **not** auto-loaded. Wire them in `onWorkerStart.php`:
```php
$stdObject-><task_name> = include __DIR__ . '/../Tasks/<task_name>.php';
```

Event files in `workerman/src/Events/` **are** auto-loaded by `VpsServer.php` via `glob` — no manual wiring needed.

Verify: calling `$stdObject-><task_name>()` resolves via `stdObject::__call` without `"undefined method"`.

### 5. Access shared state

```php
global $global;  // \GlobalData\Client — initialized in onWorkerStart.php
$global->myKey = $value;

$stdObject->config['timers']['<task_name>'];   // timer interval
$stdObject->config['options']['some_option'];  // other config
```

Config is merged from `workerman/config.ini.dist` + optional `workerman/config.ini`.

## Examples

**User says:** "Add a background task that checks disk usage every 5 minutes and logs a warning if any mount is over 90%."

1. Create `workerman/src/Tasks/check_disk_usage.php`:
```php
<?php
use Workerman\Worker;

return function ($stdObject) {
    $output = shell_exec("df --output=pcent,target | tail -n +2");
    foreach (explode("\n", trim($output)) as $line) {
        if (preg_match('/(\d+)%\s+(.+)/', $line, $m) && (int)$m[1] >= 90) {
            Worker::safeEcho("WARN: disk {$m[2]} at {$m[1]}%" . PHP_EOL);
        }
    }
};
```
2. Add to `workerman/src/Config/settings.php` `'timers'` array: `'check_disk_usage' => 300,`
3. Add to `workerman/src/Events/setupTimers.php`: `$stdObject->addTimer('check_disk_usage');`
4. Add to `workerman/src/Events/onWorkerStart.php`: `$stdObject->check_disk_usage = include __DIR__ . '/../Tasks/check_disk_usage.php';`

**Result:** Task fires every 300 s; output appears in `workerman/stdout.log`.

## Common Issues

**`Fatal error: Call to undefined method stdObject::check_disk_usage()`**  
Task not wired onto `$stdObject`. Add the `include` assignment in `onWorkerStart.php` (Step 4).

**Timer never fires**  
Key mismatch between `settings.php['timers']` and `addTimer()` string — e.g., `'check_disk_usage'` ≠ `'checkDiskUsage'`. Both must be identical.

**`Unhandled Message Type <new_type>` in stdout.log**  
The `case` string in `onMessage.php` does not match what the client sends in `$data['type']`. Grep the hub client code for the exact type string.

**Output not appearing in logs**  
Used `echo` instead of `Worker::safeEcho("..." . PHP_EOL)`. All daemon output must go through `Worker::safeEcho`.

**Closure receives wrong / shifted arguments**  
`$stdObject` is injected as argument 0 by `stdObject::__call`. Always declare `function ($stdObject, $arg1, ...)` — never omit `$stdObject` as the first parameter.

**Config key `Undefined index`**  
`$stdObject->config` merges `config.ini.dist` with `config.ini`. Add missing keys to `workerman/config.ini.dist` under the appropriate `[section]`.