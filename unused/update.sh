#!/bin/bash
export base="$(readlink -f "$(dirname "$0")")";
git pull --all -f || rsync -a rsync://vpsadmin.interserver.net/vps/cpaneldirect/ ${base}/
