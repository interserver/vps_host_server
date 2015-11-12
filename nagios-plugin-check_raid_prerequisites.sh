#!/bin/bash
if [ -e /etc/redhat-release ]; then 
  distro=centos; 
  version=$(cat /etc/redhat-release  | sed s#"^.* \([0-9]\)\..*$"#"\1"#g); 
elif [ -e /etc/apt ]; then 
  . /etc/lsb-release; 
  distro=ubuntu; 
  version=$DISTRIB_RELEASE;
fi;
if [ ! -e /usr/bin/cpan ]; then
  if [ "$distro"= "ubuntu" ];then
    apt-get install -y perl expect >&2;
  else
    yum install -y perl-CPAN yaml-cpp yaml-cpp-devel perl-YAML libyaml libyaml-devel perl-Test-CPAN-Meta-YAML perl-Test-YAML-Meta expect libserf-devel db4-devel gnome-keyring-devel >&2;
  fi;
fi;
perldir="$(perl -V |grep -v "\.$" | tail -n 1 | sed s#" "#""#g)";
rm -f ${perldir}/CPAN/Config.pm;
/root/cpaneldirect/perl_cpan_setup.exp;
if [ 1 = 0 ]; then
if [ ! -e ${perldir}/CPAN/Config.pm ] || [ "$(grep "urllist.*http" ${perldir}/CPAN/Config.pm)" = "" ]; then
cat >${perldir}/CPAN/Config.pm <<EOF

$CPAN::Config = {
  'build_cache' => q[10],
  'build_dir' => q[/root/.cpan/build],
  'cache_metadata' => q[1],
  'cpan_home' => q[/root/.cpan],
  'dontload_hash' => {  },
  'ftp' => q[],
  'ftp_proxy' => q[],
  'getcwd' => q[cwd],
  'gpg' => q[/usr/bin/gpg],
  'gzip' => q[/bin/gzip],
  'histfile' => q[/root/.cpan/histfile],
  'histsize' => q[100],
  'http_proxy' => q[],
  'inactivity_timeout' => q[0],
  'index_expire' => q[1],
  'inhibit_startup_message' => q[0],
  'keep_source_where' => q[/root/.cpan/sources],
  'links' => q[],
  'make' => q[/usr/bin/make],
  'make_arg' => q[],
  'make_install_arg' => q[],
  'makepl_arg' => q[],
  'ncftp' => q[],
  'ncftpget' => q[],
  'no_proxy' => q[],
  'pager' => q[/usr/bin/less],
  'prerequisites_policy' => q[ask],
  'scan_cache' => q[atstart],
  'shell' => q[/bin/bash],
  'tar' => q[/bin/tar],
  'term_is_latin' => q[1],
  'unzip' => q[/usr/bin/unzip],
  'urllist' => [q[ftp://cpan-du.viaverio.com/pub/CPAN/], q[ftp://cpan.cse.msu.edu/], q[ftp://cpan.erlbaum.net/CPAN/]],
  'wget' => q[/usr/bin/wget],
};
1;
__END__
EOF
fi;
fi;
modules="Module::Build Test::Fatal Test::Requires Module::Implementation Params::Validate Monitoring::Plugin Module::Pluggable ExtUtils::MakeMaker ExtUtils::MakeMaker::CPANfile";
for i in $modules; do 
  #PERL_MM_USE_DEFAULT=1 perl -MCPAN -e "install $i" >&2
  PERL_MM_USE_DEFAULT=1 cpan $i >&2
done;
