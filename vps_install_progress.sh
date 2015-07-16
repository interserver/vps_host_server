#!/bin/bash
base="$(readlink -f "$(dirname "$0")")";
echo "$($base/vps_install_progress_method_2.sh) ($($base/vps_install_progress_method_1.sh)%) on $(ps x |grep "/bin/bash /root/cpaneldirect/vps_kvm_create.sh" |grep -v " grep " | awk '{ print $7 }')"
