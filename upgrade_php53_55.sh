#!/bin/bash
if [ -e /etc/yum ]; then
  if [ "$(env php -v 2>/dev/null|head -n 1|cut -d" " -f2|cut -d\. -f1-2)" = "5.3" ]; then
    old="$(rpm -qa|grep php)";
    eval yum install -y php55 php55-php-mbstring php55-php-opcache php55-php-xmlrpc php55-php-intl php55-php-$(rpm -qa|grep php|grep "php-[a-z]"|cut -d- -f2|tr "\n" " "|sed s#" $"#""#g|sed s#" "#" php55-php-"#g) && rpm -e $old;
    cd /usr/local/bin;
    for i in /opt/rh/php55/root/usr/bin/*; do
      ln -sf "$i";
    done;
  fi;
  if [ "$(rpm -qa|grep -e "^libevent-[a-z]")" != "" ]; then
    rpm -e libevent-headers libevent-doc libevent-devel;
  fi;
  if [ $(rpm -qa|grep -e libevent2 -e libev-|wc -l) -lt 4 ]; then
    yum install -y libev libev-devel libevent2 libevent2-devel;
  fi;
fi
if [ "$(env pecl list|grep "^eio")" = "" ]; then
  echo -e "\n"| env pecl install -a eio;
fi;
#if [ "$(env php -m 2>/dev/null|grep eio)" = "" ]; then
#  echo "extension=eio.so" > $(env php -i 2>/dev/null|grep php.ini|grep "^Loaded Config"|awk "{ print \$5 }"|xargs dirname)/php.d/eio.ini;
#fi;
if [ "$(env pecl list|grep "^event")" = "" ]; then
  echo -e "\n\n\n\n\n\n\n"| env pecl install -a event;
fi;
#if [ "$(env php -m 2>/dev/null|grep event)" = "" ]; then
#  echo "extension=event.so" > $(env php -i 2>/dev/null|grep php.ini|grep "^Loaded Config"|awk "{ print \$5 }"|xargs dirname)/php.d/event.ini;
#fi;
if [ "$(env pecl list|grep "^proctitle")" = "" ]; then
  echo -e "\n"| env pecl install -a "channel://pecl.php.net/proctitle-0.1.2";
fi;
if [ "$(env php -m 2>/dev/null|grep swoole)" = "" ]; then
  echo -e "\nyes\nyes\n\n\n\n" | env pecl install -a swoole;
  echo "extension=swoole.so" > $(env php -i 2>/dev/null|grep php.ini|grep "^Loaded Config"|awk "{ print \$5 }"|xargs dirname)/php.d/swoole.ini;
fi;

