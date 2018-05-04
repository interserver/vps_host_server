#!/bin/bash
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
				yum makecache; 
			else
				rm -f /etc/yum.repos.d/WANdisco*; 
			fi; 
			yum upgrade -y subversion; 
		elif [ -e /etc/apt ]; then 
			. /etc/lsb-release; 
			distro=ubuntu;
			version=$DISTRIB_RELEASE;
			echo -e "# WANdisco Open Source Repo\ndeb http://opensource.wandisco.com/ubuntu ${DISTRIB_CODENAME} svn${svnversionshort}" > /etc/apt/sources.list.d/WANdisco-svn${svnversionshort}.list; 
			apt-get update; 
			apt-get install subversion -y; 
		fi;
	fi;
}
function svn_up() {
	svn update --accept theirs-full --username vpsclient --password interserver123 --trust-server-cert --non-interactive /root/cpaneldirect
}
function check_composer() {
	if [ "$(which composer)" = "" ]; then
		curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer --ansi;
	fi;
}
function check_php() {
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
	inifile="$(php -i |grep 'Loaded Configuration' |awk '{ print $5 }')"
	sed s#"^memory_limit = .*$"#"memory_limit = 512M"#g -i "$inifile"
}
function composer_up() {
	composer update --with-dependencies -v -o --ansi --no-dev
	cat vendor/phpsysinfo/phpsysinfo/phpsysinfo.ini.new > vendor/phpsysinfo/phpsysinfo/phpsysinfo.ini
}
check_svn
svn_up
check_php
check_composer
composer_up
