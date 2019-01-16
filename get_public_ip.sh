#!/bin/bash
unset ip;
if [ "$(which dig)" != "" ]; then
  ip="$(dig +short myip.opendns.com @resolver1.opendns.com)";
fi;
for s in ipecho.net/plain icanhazip.com ifconfig.me; do
  if [ ! -z $ip ] && [ "$(which curl)" != "" ]; then
    ip="$(curl -s http://${s})";
  fi;
  if [ ! -z $ip ] && [ "$(which wget)" != "" ]; then
    ip="$(wget http://${s} -O - -q)";
  fi;
done;
