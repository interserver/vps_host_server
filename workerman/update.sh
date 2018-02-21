#!/bin/bash
if [ "$(which composer)" = "" ]; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --ansi;
fi;
if [ -e /etc/yum ]; then
	yum install -y php php-cli php-bcmath php-devel php-gd php-process php-xml;
fi
sed s#"^memory_limit = .*$"#"memory_limit = 512M"#g -i /etc/php.ini
composer update --with-dependencies -v -o --ansi --no-dev

