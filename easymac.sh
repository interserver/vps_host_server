#!/bin/bash
# Script for generating VMware MAC Addresses
# http://www.easyvmx.com
#
# Works on any *NIX system with standard Bourne Shell and /dev/urandom
#
# Freely distributable under the BSD license:
#
# ------------- Start licence --------------
# Copyright (c) 2006, http://www.easyvmx.com
# All rights reserved.
#
# Redistribution and use in source and binary forms, with or without
# modification, are permitted provided that the following conditions are met:
#
# Redistributions of source code must retain the above copyright notice,
# this list of conditions and the following disclaimer.
#
# Redistributions in binary form must reproduce the above copyright notice,
# this list of conditions and the following disclaimer in the documentation
# and/or other materials provided with the distribution.
#
# Neither the name of the <ORGANIZATION> nor the names of its contributors
# may be used to endorse or promote products derived from this software without
# specific prior written permission.
#
# THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
# AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO,
# THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
# ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
# LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
# CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
# SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
# INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
# CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
# ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
# POSSIBILITY OF SUCH DAMAGE.
# ------------- End licence --------------
#
# Changelog
# ---------
# 2006-11-06:
#	Version 1.0, first release of EasyMAC!
#
# 2007-07-20:
#	Version 1.1:
#		Added option for _any_ MAC address, not only VMware addresses.
#		Changed output for static/random MAC address. It now tells that these are VMware MAC adresses.
#
# 2008-10-08:
#	Version 1.2:
#		Added option for XenSource MAC address creation.
#		You can now output MAC address only, without the leading text.
#
# 2009-04-08:
#	Version 1.3:
#		Changed the way XenSource MAC addresses are created.

#
# Version
#

export PATH="/usr/local/bin:/usr/local/sbin:$PATH:/bin:/usr/bin:/sbin:/usr/sbin"

EMVersion=1.r3
ReleaseYear=2009
ReleaseDate=2009-04-08


#
# Functions
#

# Random VMware MAC Address
vmrandom() {
	vmrandmac=$(dd if=/dev/urandom bs=1 count=3 2>/dev/null | od -tx1 | head -1 | cut -d' ' -f2- | awk '{ print "00:0c:29:"$1":"$2":"$3 }')
	echo $vmrandmac
}

# Static VMware MAC Address
vmstatic() {
	max3f=$(printf "%02X" $(expr $(dd if=/dev/urandom bs=1 count=1 2>/dev/null | od -tu1 | head -1 | cut -d' ' -f2-) / 4) | tr A-Z a-z)
	vmstatmac=$(echo -n "00:50:56:$max3f:" $(dd if=/dev/urandom bs=1 count=2 2>/dev/null | od -tx1 | head -1 | cut -d' ' -f2- | awk '{ print $1":"$2 }') | sed 's/\ //')
	echo $vmstatmac
}

# Global MAC Address (any valid MAC address, from the full range)
global() {
        globalmac=$(dd if=/dev/urandom bs=1 count=6 2>/dev/null | od -tx1 | head -1 | cut -d' ' -f2- | awk '{ print $1":"$2":"$3":"$4":"$5":"$6 }')
        echo $globalmac
}

# XenSource MAC Address
xensource() {
	max3f=$(printf "%02X" $(expr $(dd if=/dev/urandom bs=1 count=1 2>/dev/null | od -tu1 | head -1 | cut -d' ' -f2-) / 4) | tr A-Z a-z)
	xensource=$(echo -n "00:50:56:$max3f:" $(dd if=/dev/urandom bs=1 count=2 2>/dev/null | od -tx1 | head -1 | cut -d' ' -f2- | awk '{ print $1":"$2 }') | sed 's/\ //')
	echo $xensource
}


#
# Process options
#

case "$1" in

	r|-r|random|-random)
		if [ "$2" != "-m" ]; then
			echo -n "Random VMware MAC Address: "
		fi
		vmrandom
		echo ""
		;;

	R|-R|RANDOM|-RANDOM|Random|-Random)
		if [ "$2" != "-m" ]; then
			echo -n "Random VMware MAC Address: "
		fi
		vmrandom | tr a-z A-Z
		echo ""
		;;

	s|-s|static|-static)
		if [ "$2" != "-m" ]; then
			echo -n "Static VMware MAC Address: "
		fi
		vmstatic
		echo ""
		;;

	S|-S|STATIC|-STATIC|Static|-Static)
		if [ "$2" != "-m" ]; then
			echo -n "Static VMware MAC Address: "
		fi
		vmstatic | tr a-z A-Z
		echo ""
		;;

	g|-g|global|-global)
		if [ "$2" != "-m" ]; then
			echo -n "Global MAC Address: "
		fi
		global
		echo ""
		;;

	G|-G|GLOBAL|-GLOBAL|Global|-Global)
		if [ "$2" != "-m" ]; then
			echo -n "Global MAC Address: "
		fi
		global | tr a-z A-Z
		echo ""
		;;

	x|-x|xen|-xen)
		if [ "$2" != "-m" ]; then
			echo -n "XenSource MAC Address: "
		fi
		xensource
		echo ""
		;;

	X|-X|XEN|-XEN|Xen|-Xen)
		if [ "$2" != "-m" ]; then
			echo -n "XenSource MAC Address: "
		fi
		xensource | tr a-z A-Z
		echo ""
		;;

	*)
		echo ""
		echo "EasyMAC! v. $EMVersion"
		echo "Generate hardware adresses for virtual machines"
		echo "Copyright (c) $ReleaseYear, http://www.easyvmx.com"
		echo ""
		echo "Usage: $0 {-r|-R|-s|-S|-x|-X|-g|-G} {-m}"
		echo ""
		echo "Options:"
		echo "   -r:	Random VMware MAC address, lower case"
		echo "   -R:	Random VMware MAC address, UPPER CASE"
		echo "   -s:	Static VMware MAC address, lower case"
		echo "   -S:	Static VMware MAC address, UPPER CASE"
		echo "   -x:	XenSource MAC address, lower case"
		echo "   -X:	XenSource MAC address, UPPER CASE"
		echo "   -g:	Global MAC address, lower case"
		echo "   -G:	Global MAC address, UPPER CASE"
		echo ""
		echo "   -m:	Add the -m option for MAC address only"
		echo ""
		echo "All valid options:"
		echo "   Random VMware Lower Case: 	{r|-r|random|-random}"
		echo "   Random VMware Upper Case: 	{R|-R|RANDOM|-RANDOM|Random|-Random}"
		echo "   Static VMware Lower Case: 	{s|-s|static|-static}"
		echo "   Static VMware Upper Case: 	{S|-S|STATIC|-STATIC|Static|-Static}"
		echo "   XenSource Lower Case: 	{x|-x|xen|-xen}"
		echo "   XenSource Upper Case: 	{X|-X|XEN|-XEN|Xen|-Xen}"
		echo "   Global MAC Lower case:	{g|-g|global|-global}"
		echo "   Global MAC Upper case:	{G|-G|GLOBAL|-GLOBAL|Global|-Global}"
		echo ""
		echo "Freely distributable under the BSD license"
		echo "Visit http://www.easyvmx.com for the best online virtual machine creator!"
		echo ""
		exit 1

esac

exit $?
