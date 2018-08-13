#!/bin/bash
rsync -av rsync://vpsadmin.interserver.net/vps/cpaneldirect/ /root/cpaneldirect/ || svn update --accept theirs-full --username vpsclient --password interserver123 --trust-server-cert --non-interactive /root/cpaneldirect;
