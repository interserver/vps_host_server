#!/bin/bash
if [ -e /etc/redhat-release ] ; then
	yum install pykickstart -y;
elif [ -e /etc/apt ]; then
	apt-get install python-pykickstart -y;
fi;

