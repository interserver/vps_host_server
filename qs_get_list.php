#!/usr/bin/env php
<?php
/**
 * qs_get_list.php — RETIRED collector, kept only as a compatibility entrypoint.
 *
 * This script used to build the quickservers server_list itself and POST it
 * with a bare `curl -d action=server_list -d servers=...`, which bypassed the
 * queue envelope layer entirely: the panel logged every one of those as
 * "action=server_list ... sealed=0 outcome=legacy_plaintext". qs_cron.sh has
 * already replaced it with `provirted.phar cron vps-info -a`, whose send goes
 * through App\QueueGateway::sendRequest (seals the payload, opens the response
 * leg, self-enrols the host).
 *
 * Rather than keep a second, unsealed copy of the same collector alive, this
 * delegates to the phar. Anything still invoking qs_get_list.php keeps working
 * and stops showing up on the legacy path.
 *
 * @author Joe Huss <detain@interserver.net>
 * @package MyAdmin
 * @category VPS
 */

$phar = __DIR__.'/provirted.phar';
if (!is_file($phar)) {
	fwrite(STDERR, "qs_get_list.php: {$phar} not found; cannot send the quickservers server_list.\n");
	exit(1);
}
$cmd = escapeshellarg($phar).' cron vps-info -a';
passthru($cmd, $exitCode);
exit((int) $exitCode);
