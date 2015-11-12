#!/bin/bash
for i in  Monitoring::Plugin Module::Pluggable ExtUtils::MakeMaker ExtUtils::MakeMaker::CPANfile ; do 
	PERL_MM_USE_DEFAULT=1 cpan $i >&2
done;
