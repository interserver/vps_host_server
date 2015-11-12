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
echo 'JENQQU46OkNvbmZpZyA9IHsKICAnYXV0b19jb21taXQnID0+IHFbMF0sCiAgJ2J1aWxkX2NhY2hlJyA9PiBxWzEwMF0sCiAgJ2J1aWxkX2RpcicgPT4gcVsvcm9vdC8uY3Bhbi9idWlsZF0sCiAgJ2J1aWxkX2Rpcl9yZXVzZScgPT4gcVswXSwKICAnYnVpbGRfcmVxdWlyZXNfaW5zdGFsbF9wb2xpY3knID0+IHFbYXNrL3llc10sCiAgJ2NhY2hlX21ldGFkYXRhJyA9PiBxWzFdLAogICdjaGVja19zaWdzJyA9PiBxWzBdLAogICdjb21tYW5kbnVtYmVyX2luX3Byb21wdCcgPT4gcVsxXSwKICAnY29ubmVjdF90b19pbnRlcm5ldF9vaycgPT4gcVswXSwKICAnY3Bhbl9ob21lJyA9PiBxWy9yb290Ly5jcGFuXSwKICAnZnRwX3Bhc3NpdmUnID0+IHFbMV0sCiAgJ2Z0cF9wcm94eScgPT4gcVtdLAogICdnZXRjd2QnID0+IHFbY3dkXSwKICAnaGFsdF9vbl9mYWlsdXJlJyA9PiBxWzBdLAogICdodHRwX3Byb3h5JyA9PiBxW10sCiAgJ2luYWN0aXZpdHlfdGltZW91dCcgPT4gcVswXSwKICAnaW5kZXhfZXhwaXJlJyA9PiBxWzFdLAogICdpbmhpYml0X3N0YXJ0dXBfbWVzc2FnZScgPT4gcVswXSwKICAna2VlcF9zb3VyY2Vfd2hlcmUnID0+IHFbL3Jvb3QvLmNwYW4vc291cmNlc10sCiAgJ2xvYWRfbW9kdWxlX3ZlcmJvc2l0eScgPT4gcVt2XSwKICAnbWFrZV9hcmcnID0+IHFbXSwKICAnbWFrZV9pbnN0YWxsX2FyZycgPT4gcVtdLAogICdtYWtlX2luc3RhbGxfbWFrZV9jb21tYW5kJyA9PiBxW10sCiAgJ21ha2VwbF9hcmcnID0+IHFbSU5TVEFMTERJUlM9c2l0ZV0sCiAgJ21idWlsZF9hcmcnID0+IHFbXSwKICAnbWJ1aWxkX2luc3RhbGxfYXJnJyA9PiBxW10sCiAgJ21idWlsZF9pbnN0YWxsX2J1aWxkX2NvbW1hbmQnID0+IHFbLi9CdWlsZF0sCiAgJ21idWlsZHBsX2FyZycgPT4gcVstLWluc3RhbGxkaXJzIHNpdGVdLAogICdub19wcm94eScgPT4gcVtdLAogICdwYWdlcicgPT4gcVsvdXNyL2Jpbi9sZXNzXSwKICAncGVybDVsaWJfdmVyYm9zaXR5JyA9PiBxW3ZdLAogICdwcmVmZXJfaW5zdGFsbGVyJyA9PiBxW01CXSwKICAncHJlZnNfZGlyJyA9PiBxWy9yb290Ly5jcGFuL3ByZWZzXSwKICAncHJlcmVxdWlzaXRlc19wb2xpY3knID0+IHFbYXNrXSwKICAnc2Nhbl9jYWNoZScgPT4gcVthdHN0YXJ0XSwKICAnc2hlbGwnID0+IHFbL2Jpbi9iYXNoXSwKICAnc2hvd191cGxvYWRfZGF0ZScgPT4gcVswXSwKICAndGFyX3ZlcmJvc2l0eScgPT4gcVt2XSwKICAndGVybV9pc19sYXRpbicgPT4gcVsxXSwKICAndGVybV9vcm5hbWVudHMnID0+IHFbMV0sCiAgJ3RydXN0X3Rlc3RfcmVwb3J0X2hpc3RvcnknID0+IHFbMF0sCiAgJ3VybGxpc3QnID0+IFtxW2h0dHA6Ly9taXJyb3JzLnJpdC5lZHUvQ1BBTi9dLCBxW2h0dHA6Ly9jcGFuLm1pcnJvcnMuaW9uZmlzaC5vcmcvXSwgcVtodHRwOi8vY3Bhbi5uZXRuaXRjby5uZXQvXV0sCiAgJ3VzZV9zcWxpdGUnID0+IHFbMF0sCiAgJ3lhbWxfbG9hZF9jb2RlJyA9PiBxWzBdLAp9OwoxOwpfX0VORF9fCg=' | base64 -d - > ${perldir}/CPAN/Config.pm;
for i in Parse::CPAN::Meta Monitoring::Plugin Module::Pluggable ExtUtils::MakeMaker ExtUtils::MakeMaker::CPANfile ; do 
	PERL_MM_USE_DEFAULT=1 cpan $i >&2
done;
