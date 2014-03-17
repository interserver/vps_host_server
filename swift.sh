#!/bin/bash

cd /root/cpaneldirect

if [ -e tmp.sh ]; then
	echo "Found tmp.sh, remove first";
	exit;
fi

gpg --output tmp.sh -d swift.gpg

if [ -e tmp.sh ]; then
	sh tmp.sh
	rm tmp.sh
else
	echo 'Decrypt failed';
fi
