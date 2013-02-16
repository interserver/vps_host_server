#!/bin/bash
export PATH="$PATH:/bin:/usr/bin:/usr/local/bin:/sbin:/usr/sbin:/usr/local/sbin"
if [ "$(ps aux| grep 'php qs_cron.php' | grep -v "grep php" |wc -l)" = "0" ]; then
	php qs_cron.php >> cron.output 2>&1
fi
