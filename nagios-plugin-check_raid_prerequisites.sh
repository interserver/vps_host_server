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
    apt-get install -y perl;
 else
    yum install -y perl-CPAN;
fi;
echo 'H4sICANDRFYCA0NvbmZpZy5wbQB9VD1v2zAQ3f0rNBTIkljJ0CVBCuSjQ4A0KJpuQXCgpbN1MEXS
d6QTo+h/L0XRTiTbXQz53rvve/xy9/Pm6fLyzpo5LYrr4s+kKE5U8BYq27bkT4rrb8Xq5fz1tANm
gXQNlaoazMDF+QCqiTNQsrW+nFZOmTJhIxowBsFD4RlXgRgFyIhXWoOzmqpNpipZlhuU3iFVAi16
VSuvtiVlrMFqCUILGSbp+lKmNqGdIccc4Ni2zo+crTFYeYhzIOORDXqwy1Gg2Bo0tsX9jnvC3Dtw
SoTWOIyeALbv25566wJ99VZnU/zqrY3SMbWBuSIdeDSwxh+MREZVntbkN+CpRRtGayRT4zvguyMe
VUamoRl5iINnH1wcrYhajLIuER2IDVwhvDXIBwZQ9nBek7aqhtbWQSOskWdWYmnZad1TWrVEULwY
9JGM2yv4L5j+5M3usdxn54en5983j4/3D7+er2MZmGn96e3l6M1HSxjCWR2DKqbl7cfx9/xBOWdn
2TtKQoqPgow9sFcXV7HTVxAuZ2RKHVeUYWT9VdPs2Iwd4zydfEq4i/Tj9gOWIwpO2I6WFNrVKnva
7DlSRWl8fiaUTxeV0Qa13ibpWpgpabaQfYPg0sFESY8OL4Y41lvUaAskoJUnM7zpBFk2qkXjZYRx
kKhyjD+MzrKHhsRb3gwTB9Y62pPxZfXSye6yLFtitixTJj/FOpTdU1q+nhY7Qje66ZZF3RMrzdTy
Yp8U3xdDvrLdR/makwqCrDSNp7BRrYY0ocrWn7G/V5OLqwnA96d7gMk/S+BuINoFAAA=' | \
base64 -d - |gunzip - > /usr/share/perl5/CPAN/Config.pm;
for i in  Monitoring::Plugin Module::Pluggable ExtUtils::MakeMaker ExtUtils::MakeMaker::CPANfile ; do 
	PERL_MM_USE_DEFAULT=1 cpan $i >&2
done;
