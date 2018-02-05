#!/bin/bash
if [ "$(which composer)" = "" ]; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --ansi;
fi;
composer update --with-dependencies -v -o --ansi --no-dev
