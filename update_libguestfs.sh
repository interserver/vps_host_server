#!/bin/bash
if [ "$(which equivs 2>/dev/null)" = "" ]; then apt-get install -y equivs; fi;
if [ "$(which dpkg-source 2>/dev/null)" = "" ]; then apt-get install -y dpkg-dev; fi;
if [ "$(which mk-build-deps 2>/dev/null)" = "" ]; then apt-get install -y devscripts; fi;
pkg=libguestfs
path="$(apt-cache showsrc ${pkg}|grep ^Directory|cut -d" " -f2|head -n1)"
url="http://us.archive.ubuntu.com/ubuntu/${path}/"
latest="$(curl -s "${url}"|grep "${pkg}_.*dsc"|cut -d\" -f8|sort -nr|head -n 1|sed s#"^${pkg}_\(.*\).dsc$"#"\1"#g)"
installed="$(dpkg -l |grep ${pkg}0|awk '{ print $3 }'|cut -d: -f2)"
echo "Latest version: $latest     Installed Version: $installed"
if [ "$latest" = "$installed" ]; then echo "No Update Needed"; exit; fi
wget "${url}${pkg}_${latest}.dsc" -O "${pkg}_${latest}.dsc"
for i in $(cat ${pkg}_${latest}.dsc|grep "^ [^ ]* [0-9]* "|awk '{ print $3 }'|sort|uniq); do
    wget "${url}$i" -O "$i" # grab all the files needed to build package
done
dir="$(echo "${pkg}-${latest}"|cut -d- -f1-2)" # dir extracted source will go into
rm -rf $dir
dpkg-source -x "${pkg}_${latest}.dsc" # extract the sourcecode
cd $dir
echo y|mk-build-deps -i || exit # create and install metapackage for all missing deps
rm -f ${pkg}-build-deps_${latest}_amd64.deb
debuild || exit # build deb
apt-get purge -y --auto-remove ${pkg}-build-deps # remove deps installed just for build
cd ..
packages="$(grep "^Package" debian/control |cut -d" " -f2)" # list of packages this source builds
installedpkgs="$(echo "$packages"|sed -e s#" $"#""#g -e s#" "#" -e "#g)|awk '{ print $2 }'|cut -d: -f1)" # installed packages this builds
upgradepkgs="$(eval dpkg -l |grep -e $(echo "${installedpkgs}"|tr "\n" " "|sed s#" "#"_${latest}\*deb "#g)" # new package upgrades
eval dpkg --install ${pkg}-tools_${latest}_amd64.deb ${upgradepkgs}; # upgrade
