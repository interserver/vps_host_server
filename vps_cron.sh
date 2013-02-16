#!/bin/bash
export PATH="$PATH:/bin:/usr/bin:/usr/local/bin:/sbin:/usr/sbin:/usr/local/sbin"
if [ "$(ps aux| grep 'php vps_cron.php' | grep -v "grep php" |wc -l)" = "0" ]; then
	php vps_cron.php >> cron.output 2>&1
fi
