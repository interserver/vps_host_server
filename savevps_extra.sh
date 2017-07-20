#!/bin/bash
function runningvps() { virsh list | grep running | awk '{ print ($3 == "running") ? $2 : "" }'; }; \
function runcount() { runningvps | wc -l; }; \
function lastvps() { runningvps | tail -n "1"; }; \
while [ "$(runcount)" -ge 3 ]; do
  echo "Running $(runcount)"; \
  vps="$(lastvps)"; \
  echo "Last VPS $vps $(lastvps)"; \
  if [ ! -z "$vps" ]; then
    echo "Saving VPS $vps"; \
    /root/cpaneldirect/savevps.sh "$vps"; \
    echo "Finished Saving $vps";
  fi;
done; \
echo "All Done";

