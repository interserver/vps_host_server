#!/bin/bash
p="$(dpkg -S /boot/*generic 2>/dev/null|cut -d: -f1|grep -v -e $(uname -r) $(dpkg -l|grep "linux-image-[0-9]"|tail -n 2|cut -d- -f3-4|sed s#"^"#" -e "#g|tr "\n" " ")|sort|uniq)";
dpkg --remove $p;
dpkg --purge $p;
