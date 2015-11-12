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
    apt-get install -y perl >&2;
  else
    yum install -y perl-CPAN yaml-cpp yaml-cpp-devel perl-YAML libyaml libyaml-devel perl-Test-CPAN-Meta-YAML perl-Test-YAML-Meta >&2;
  fi;
fi;
perldir="$(perl -V |grep -v "\.$" | tail -n 1 | sed s#" "#""#g)";
if [ ! -e ${perldir}/CPAN/Config.pm ] || [ "$(grep "urllist.*http" ${perldir}/CPAN/Config.pm)" = "" ]; then
cat >${perldir}/CPAN/Config.pm <<EOF
$CPAN::Config = {
  'auto_commit' => q[0],
  'build_cache' => q[100],
  'build_dir' => q[/root/.cpan/build],
  'build_dir_reuse' => q[0],
  'build_requires_install_policy' => q[ask/yes],
  'cache_metadata' => q[1],
  'check_sigs' => q[0],
  'commandnumber_in_prompt' => q[1],
  'connect_to_internet_ok' => q[0],
  'cpan_home' => q[/root/.cpan],
  'ftp_passive' => q[1],
  'ftp_proxy' => q[],
  'getcwd' => q[cwd],
  'halt_on_failure' => q[0],
  'http_proxy' => q[],
  'inactivity_timeout' => q[0],
  'index_expire' => q[1],
  'inhibit_startup_message' => q[0],
  'keep_source_where' => q[/root/.cpan/sources],
  'load_module_verbosity' => q[v],
  'make_arg' => q[],
  'make_install_arg' => q[],
  'make_install_make_command' => q[],
  'makepl_arg' => q[INSTALLDIRS=site],
  'mbuild_arg' => q[],
  'mbuild_install_arg' => q[],
  'mbuild_install_build_command' => q[./Build],
  'mbuildpl_arg' => q[--installdirs site],
  'no_proxy' => q[],
  'pager' => q[/usr/bin/less],
  'perl5lib_verbosity' => q[v],
  'prefer_installer' => q[MB],
  'prefs_dir' => q[/root/.cpan/prefs],
  'prerequisites_policy' => q[ask],
  'scan_cache' => q[atstart],
  'shell' => q[/bin/bash],
  'show_upload_date' => q[0],
  'tar_verbosity' => q[v],
  'term_is_latin' => q[1],
  'term_ornaments' => q[1],
  'trust_test_report_history' => q[0],
  'urllist' => [q[http://mirrors.rit.edu/CPAN/], q[http://cpan.mirrors.ionfish.org/], q[http://cpan.netnitco.net/]],
  'use_sqlite' => q[0],
  'yaml_load_code' => q[0],
};
1;
__END__
EOF
fi;
for i in Parse::CPAN::Meta Monitoring::Plugin Module::Pluggable ExtUtils::MakeMaker ExtUtils::MakeMaker::CPANfile ; do 
	PERL_MM_USE_DEFAULT=1 cpan $i >&2
done;
