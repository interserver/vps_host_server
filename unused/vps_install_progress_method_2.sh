#!/bin/bash
{
	cat /proc/$(pidof dd)/fd/2 > dd.out 2>/dev/null &
	while [ "$(grep "^[[:digit:]]*.*bytes.*copied.*/s$" dd.out 2>/dev/null)" = "" ]; do
		killall -USR1 dd
		sleep 0.1s;
	done
	grep "^[[:digit:]]*.*bytes.*copied.*/s$" dd.out
	killall cat >/dev/null 2>/dev/null
	rm -f dd.out;
} 2>&1 | grep -v Terminated
