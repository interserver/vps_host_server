#!/bin/bash
export base="$(readlink -f "$(dirname "$0")")";

function stop_service() {
	./start.php stop
	killall -9 vmstat
}

function check_svn() {
	svnversion=1.9;
	svnversionshort=$(echo "$svnversion" | sed s#"\."#""#g);
	if [ ! -e /usr/bin/svn ] || [ $(svn --version |head -n 1 | sed s#"^.* \([0-9]\)\.\([0-9]\).*$"#"\1\2"#g) -lt ${svnversionshort} ]; then
		echo Upgrading SVN;
		if [ -e /etc/redhat-release ]; then
			distro=centos;
			version=$(cat /etc/redhat-release  | sed s#"^.* \([0-9]\)\..*$"#"\1"#g);
			if [ $version -lt 70 ]; then
				echo -e "[WANdisco-svn${svnversionshort}]\nname=WANdisco SVN Repo ${svnversion}\nenabled=1\nbaseurl=http://opensource.wandisco.com/centos/${version}/svn-${svnversion}/RPMS/\$basearch/\ngpgcheck=1\ngpgkey=http://opensource.wandisco.com/RPM-GPG-KEY-WANdisco" > /etc/yum.repos.d/WANdisco-svn${svnversion}.repo;
				sudo yum makecache;
			else
				rm -f /etc/yum.repos.d/WANdisco*;
			fi;
			sudo yum upgrade -y subversion;
		elif [ -e /etc/apt ]; then
			. /etc/lsb-release;
			distro=ubuntu;
			version=$DISTRIB_RELEASE;
			sudo echo -e "# WANdisco Open Source Repo\ndeb http://opensource.wandisco.com/ubuntu ${DISTRIB_CODENAME} svn${svnversionshort}" > /etc/apt/sources.list.d/WANdisco-svn${svnversionshort}.list;
			sudo apt-get update;
			sudo apt-get install subversion -y;
		fi;
	fi;
	if [ "$(which git)" = "" ]; then
		if [ -e /etc/redhat-release ]; then
			sudo yum install -y git;
		else
			sudo apt-get install -y git;
		fi
	fi;
}

function svn_up() {
	rsync -av rsync://vpsadmin.interserver.net/vps/cpaneldirect/ ${base}/../ || svn update --accept theirs-full --username vpsclient --password interserver123 --trust-server-cert --non-interactive ${base}/../;
}

function check_composer() {
	if [ "$(which composer)" = "" ]; then
		sudo curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --ansi;
	fi;
}

function check_packages() {
	if [ "$(which iostat 2>/dev/null)" = "" ]; then
		if [ "$(which yum)" != "" ]; then
			yum -y install sysstat;
		else
			apt-get -y install sysstat;
		fi;
	fi;
	if [ "$(which ioping 2>/dev/null)" = "" ]; then
		if [ -e /usr/bin/apt-get ]; then
			apt-get update;
			apt-get install -y ioping;
		else
			if [ "$(which rpmbuild 2>/dev/null)" = "" ]; then
				yum install -y rpm-build;
			fi;
			if [ "$(which make 2>/dev/null)" = "" ]; then
				yum install -y make;
			fi;
			if [ ! -e /usr/include/asm/unistd.h ]; then
				yum install -y kernel-headers;
			fi;
			wget http://mirror.trouble-free.net/tf/SRPMS/ioping-0.9-1.el6.src.rpm -O ioping-0.9-1.el6.src.rpm;
			export spec="/$(rpm --install ioping-0.9-1.el6.src.rpm --nomd5 -vv 2>&1|grep spec | cut -d\; -f1 | cut -d/ -f2-)";
			rpm --upgrade $(rpmbuild -ba $spec |grep "Wrote:.*ioping-0.9" | cut -d" " -f2);
			rm -f ioping-0.9-1.el6.src.rpm;
		fi;
	fi;
}

function check_php() {
	if [ -e /etc/apt ]; then
		. /etc/lsb-release ;
		sudo apt install -y -f php-cli libssl-dev pkg-config php-pear;
		if [ "$DISTRIB_CODENAME" = "trusty" ]; then
			sudo apt install -y php5-dev php5-curl;
		else
			sudo apt install -y php-dev php-curl php-pear libev4 libev-dev libevent-dev php-bcmath php-curl php-xml php-bz2 php-zip php-mbstring php-imagick php-intl php-json php-soap;
		fi;
	elif [ -e /etc/yum ]; then
		sudo rpm -e libevent-devel libevent-headers libevent-doc
		yum install -y php-cli php-bcmath php-devel php-gd php-process php-xml php-curl php-pear;
		sudo yum install -y openssl-devel gcc libev libevent2 libev-devel libevent2-devel  || yum install openssl-devel gcc libev-devel libevent-devel libev libevent -y
		sudo yum install -y libevent-devel
	fi
	inifile="$(php -i |grep 'Loaded Configuration' |awk '{ print $5 }')"
	sudo sed s#"^memory_limit = .*$"#"memory_limit = 512M"#g -i "$inifile"
	if [ "$(date +%Z)" = "PDT" ]; then
		sudo sed s#";date.timezone =.*$"#"date.timezone = America/Los_Angeles"#g -i "$inifile"
	else
		sudo sed s#";date.timezone =.*$"#"date.timezone = America/New_York"#g -i "$inifile"
	fi
}

function check_php_event() {
	if [ "$(php -m|grep event)" = "" ]; then
		pecl download event
		tar xvzf event-2.4.1.tgz
		cd event-2.4.1/
		phpize
		./configure --with-event-core --with-event-pthreads --with-event-extra --with-event-openssl --enable-event-sockets
		make
		sudo make install
		cd ..
		rm -rf event-2.4.1 event-2.4.1.tgz
		if [ -e /etc/apt ]; then
			for i in /etc/php/*/mods-available; do
				sudo echo extension=event.so > $i/event.ini
			done
		else
			sudo echo extension=event.so > /etc/php.d/event.ini
		fi
		sudo phpenmod -s ALL -v ALL event
	fi
}

function check_php_ev() {
	if [ "$(php -m|grep ev$)" = "" ]; then
		pecl download ev
		tar xvzf ev-1.0.4.tgz
		cd ev-1.0.4/
		phpize
		./configure --enable-ev
		make
		sudo make install
		cd ..
		rm -rf ev-1.0.4 ev-1.0.4.tgz
		if [ -e /etc/apt ]; then
			for i in /etc/php/*/mods-available; do
				sudo echo extension=ev.so > $i/ev.ini
			done
		else
			sudo echo extension=ev.so > /etc/php.d/ev.ini
		fi
		sudo phpenmod -s ALL -v ALL ev
	fi
}

function check_php_swoole() {
	if [ "$(php -m|grep swoole)" = "" ]; then
		if [ $(php-config --version|cut -c1) -ge 7 ]; then
			git clone https://github.com/swoole/swoole-src.git
		else
			wget https://github.com/swoole/swoole-src/archive/v1.10.5.tar.gz
			tar xvzf v1.10.5.tar.gz
			cd swoole-src-1.10.5
			rm -f v1.10.5.tar.gz
			mv -f swoole-src-1.10.5 swoole-src
		fi
		cd swoole-src
		phpize
		./configure --enable-sockets --enable-openssl --with-swoole
		make && sudo make install
		cd ..
		rm -rf swoole-src
		if [ -e /etc/apt ]; then
			for i in /etc/php/*/mods-available; do
				sudo echo extension=swoole.so > $i/swoole.ini
			done
			sudo phpenmod -v ALL -s ALL swoole
		else
			sudo echo extension=swoole.so > /etc/php.d/swoole.ini
		fi
	fi
}

function composer_up() {
	composer install --no-dev
	cat vendor/detain/phpsysinfo/phpsysinfo.ini.new > vendor/detain/phpsysinfo/phpsysinfo.ini
	#sed -e s#'^PLUGINS=false'#'PLUGINS=Raid,PS,PSStatus,Quotas,SMART,UpdateNotifier,Uprecords,PingTest'#g -i vendor/detain/phpsysinfo/phpsysinfo.ini
	sed -e s#'^PLUGINS=.*$'#'PLUGINS=false'#g -i vendor/detain/phpsysinfo/phpsysinfo.ini
	sed -e s#'^TEMP_FORMAT="c"'#'TEMP_FORMAT="f"'#g -e s#'^SHOW_NETWORK_ACTIVE_SPEED=false'#'SHOW_NETWORK_ACTIVE_SPEED=true'#g -e s#'^REFRESH=.*$'#'REFRESH=10000'#g -i vendor/detain/phpsysinfo/phpsysinfo.ini
}

check_svn
svn_up
check_packages
check_php
check_php_event
check_php_ev
check_php_swoole
check_composer
composer_up
