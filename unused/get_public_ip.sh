#!/bin/bash
unset ip;
if [ "$(which dig)" != "" ]; then
  export ip="$(dig +short myip.opendns.com @resolver1.opendns.com)";
fi;
for s in ipecho.net/plain icanhazip.com ifconfig.me; do
  if [ ! -z $ip ] && [ "$(which curl)" != "" ]; then
    export ip="$(curl -s http://${s})";
  fi;
  if [ ! -z $ip ] && [ "$(which wget)" != "" ]; then
    export ip="$(wget http://${s} -O - -q)";
  fi;
done;
if [ ! -z $ip ] || [ "$(echo "${ip}"|grep "[0-9]*\.[0-9]")" = "" ]; then
    export ip="$(ifconfig $(ip route list | grep "^default" | sed s#"^default.*dev "#""#g | cut -d" " -f1)  |grep inet |grep -v inet6 | awk "{ print \$2 }" | cut -d: -f2)"
fi
