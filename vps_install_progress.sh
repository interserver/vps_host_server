#!/bin/bash
base="$(readlink -f "$(dirname "$0")")";
echo "$($base/vps_install_progress_method_2.sh) ($($base/vps_install_progress_method_1.sh)%)"
