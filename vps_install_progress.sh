#!/bin/bash
base="$(readlink -f "$(dirname "$0")")";
pct=$($base/vps_install_progress_method_1.sh);
meth2="$($base/vps_install_progress_method_2.sh)";
time="$(echo "${meth2}" | cut -d"," -f2 | tr "." " " | awk '{ print $1 }')";
eta="$(echo "((${time} / ${pct}) * 100) - ${time}" | bc -l | sed s#"\..*"#""#g)";
echo "${meth2}  (${pct}%) on $(ps x |grep "/bin/bash /root/cpaneldirect/vps_kvm_create.sh" |grep -v " grep " | awk '{ print $7 }') eta ${eta} seconds"
