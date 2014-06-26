#!/bin/bash
svn update --username vpsclient --password interserver123 --trust-server-cert --non-interactive /root/cpaneldirect;
mkdir -p /root/.subversion/auth/svn.simple/; 
chmod go-rwx /root/.subversion/auth;
rm -rf /root/.subversion/auth/svn.simple/* ; 
echo 'K 8
passtype
V 6
simple
K 8
password
V 14
interserver123
K 15
svn:realmstring
V 60
<https://creation.interserver.net:443> Subversion repository
K 8
username
V 9
vpsclient
END
' > /root/.subversion/auth/svn.simple/028b64818385e81b02e8e00967c930f3;
