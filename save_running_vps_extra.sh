#!/bin/bash
function runningvps() { virsh list | grep running | awk '{ print ($3 == "running") ? $2 : "" }'; }; \
function runcount() { runningvps | wc -l; }; \
function lastvps() { runningvps | tail -n 1; }; \
while [ $(runcount) -gt 4 ]; do
  echo "Running $(runcount)"; \
  vps=$(lastvps); \
  echo "Last VPS $vps $(lastvps)"; \
  if [ ! -z "$vps" ]; then
    echo "Saving VPS $vps"; \
    savevps "$vps"; \
    echo "Finished Saving $vps";
  fi;
done; \
echo "All Done";

