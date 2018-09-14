#!/bin/bash
export base="$(readlink -f "$(dirname "$0")")";
rsync -av rsync://vpsadmin.interserver.net/vps/cpaneldirect/ ${base}/ || svn update --accept theirs-full --username vpsclient --password interserver123 --trust-server-cert --non-interactive ${base};
