#!/bin/bash
if [ -e /etc/redhat-release ]; then 
  distro=centos; 
  version=$(cat /etc/redhat-release  | sed s#"^.* \([0-9]\)\..*$"#"\1"#g); 
  yum install -y perl-CPAN yaml-cpp yaml-cpp-devel perl-YAML libyaml libyaml-devel perl-Test-CPAN-Meta-YAML perl-Test-YAML-Meta expect libserf-devel db4-devel gnome-keyring-devel >&2;
  if [ ! -e /usr/bin/scons ]; then
     rpm --install http://prdownloads.sourceforge.net/scons/scons-2.4.1-1.noarch.rpm
  fi;
perldir="$(perl -V |grep -v "\.$" | tail -n 1 | sed s#" "#""#g)";
rm -f ${perldir}/CPAN/Config.pm;
/root/cpaneldirect/perl_cpan_setup.exp;
	modules="Module::Build Test::Fatal Test::Requires Module::Implementation Params::Validate Monitoring::Plugin Module::Pluggable ExtUtils::MakeMaker ExtUtils::MakeMaker::CPANfile";
	for i in $modules; do 
	  #PERL_MM_USE_DEFAULT=1 perl -MCPAN -e "install $i" >&2
	  PERL_MM_USE_DEFAULT=1 cpan $i >&2
	done;
elif [ -e /etc/apt ]; then 
  . /etc/lsb-release; 
  distro=ubuntu; 
  version=$DISTRIB_RELEASE;
  apt-get install -y perl expect libyaml-dev libyaml-libyaml-perl libyaml-perl libyaml-cpp-dev libmonitoring-plugin-perl libmodule-pluggable-perl libmodule-build-perl libtest-fatal-perl libtest-requires-perl libmodule-implementation-perl libparams-validate-perl libextutils-makemaker-cpanfile-perl >&2;
fi;
