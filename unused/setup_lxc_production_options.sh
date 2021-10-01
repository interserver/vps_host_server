#!/bin/bash
echo '
# default 16384 This specifies an upper limit on the number of events that can be queued to the corresponding inotify instance. 1
fs.inotify.max_queued_events=1048576 
# default 128   This specifies an upper limit on the number of inotify instances that can be created per real user ID. 1
fs.inotify.max_user_instances=1048576
# default 8192  This specifies an upper limit on the number of watches that can be created per real user ID. 1
fs.inotify.max_user_watches=1048576
# default 65530 This file contains the maximum number of memory map areas a process may have. Memory map areas are used as a side-effect of calling malloc, directly by mmap and mprotect, and also when loading shared libraries.
vm.max_map_count=262144
# default 0     This denies container access to the messages in the kernel ring buffer. Please note that this also will deny access to non-root users on the host system.
kernel.dmesg_restrict=1' >> /etc/sysctl.conf; echo '

*       soft    nofile  1048576
*       hard    nofile  1048576
root    soft    nofile  1048576
root    hard    nofile  1048576
*       soft    memlock unlimited' >> /etc/security/limits.conf ;
