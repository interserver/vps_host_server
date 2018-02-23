#!/bin/bash
if [ "$(which composer)" = "" ]; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --ansi;
fi;
if [ -e /etc/apt ]; then
	. /etc/lsb-release ;
	apt install -f php libssl-dev pkg-config php-pear;
	if [ "$DISTRIB_CODENAME" = "trusty" ]; then 
		apt install -y php5-dev php5-curl;
	else
		apt install -y php-dev php-curl; 
	fi;
elif [ -e /etc/yum ]; then
	yum install -y php php-cli php-bcmath php-devel php-gd php-process php-xml openssl-devel gcc php-curl libev libevent libev-devel libevent-devel php-pear;
fi
sed s#"^memory_limit = .*$"#"memory_limit = 512M"#g -i /etc/php.ini
composer update --with-dependencies -v -o --ansi --no-dev

