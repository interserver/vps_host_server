#!/bin/bash
if [ "$(which composer)" = "" ]; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --ansi;
fi;
if [ -e /etc/yum ]; then
	yum install -y php php-cli php-bcmath php-devel php-gd php-process;
fi
composer update --with-dependencies -v -o --ansi --no-dev
