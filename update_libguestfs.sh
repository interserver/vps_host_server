#!/bin/bash
if [ "$(which equivs 2>/dev/null)" = "" ]; then apt-get install -y equivs; fi;
if [ "$(which dpkg-source 2>/dev/null)" = "" ]; then apt-get install -y dpkg-dev; fi;
if [ "$(which mk-build-deps 2>/dev/null)" = "" ]; then apt-get install -y devscripts; fi;
pkg=libguestfs 
url="http://us.archive.ubuntu.com/ubuntu/pool/universe/libg/${pkg}/"
latest="$(curl -s "${url}"|grep "${pkg}_.*dsc"|cut -d\" -f8|sort -nr|head -n 1|sed s#"^${pkg}_\(.*\).dsc$"#"\1"#g)"        
installed="$(dpkg -l |grep ${pkg}0|awk '{ print $3 }'|cut -d: -f2)"    
echo "Latest version: $latest     Installed Version: $installed"
if [ "$latest" = "$installed" ]; then echo "No Update Needed"; exit; fi
wget "${url}${pkg}_${latest}.dsc" -O "${pkg}_${latest}.dsc"        
for i in $(cat ${pkg}_${latest}.dsc|grep "^ [^ ]* [0-9]* "|awk '{ print $3 }'|sort|uniq); do    
    wget "${url}$i" -O "$i"
done
dir="$(echo "${pkg}-${latest}"|cut -d- -f1-2)"    
rm -rf $dir
dpkg-source -x "${pkg}_${latest}.dsc"    
cd $dir
echo y|mk-build-deps -i || exit
rm -f ${pkg}-build-deps_${latest}_amd64.deb    
debuild || exit
apt-get purge -y --auto-remove ${pkg}-build-deps    
cd ..
packages="$(grep "^Package" debian/control |cut -d" " -f2)"
installedpkgs="$(echo "$packages"|sed -e s#" $"#""#g -e s#" "#" -e "#g)|awk '{ print $2 }'|cut -d: -f1)"
upgradepkgs="$(eval dpkg -l |grep -e $(echo "${installedpkgs}"|tr "\n" " "|sed s#" "#"_${latest}\*deb "#g)"
eval dpkg --install ${pkg}-tools_${latest}_amd64.deb ${upgradepkgs};    
