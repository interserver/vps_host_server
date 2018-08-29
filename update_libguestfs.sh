if [ "$(which equivs 2>/dev/null)" = "" ]; then apt-get install -y equivs; fi;
if [ "$(which dpkg-source 2>/dev/null)" = "" ]; then apt-get install -y dpkg-dev; fi;
if [ "$(which mk-build-deps 2>/dev/null)" = "" ]; then apt-get install -y devscripts; fi;
url="http://us.archive.ubuntu.com/ubuntu/pool/universe/libg/libguestfs/"
latest="$(curl -s "${url}"|grep "libguestfs_.*dsc"|cut -d\" -f8|sort -nr|head -n 1|sed s#"^libguestfs_\(.*\).dsc$"#"\1"#g)"                                                            
installed="$(dpkg -l |grep libguestfs0|awk '{ print $3 }'|cut -d: -f2)"
echo "Latest version: $latest     Installed Version: $installed"
if [ "$latest" = "$installed" ]; then echo "No Update Needed"; exit; fi
wget "${url}libguestfs_${latest}.dsc" -O "libguestfs_${latest}.dsc"                                                            
for i in $(cat libguestfs_${latest}.dsc|grep "^ [^ ]* [0-9]* "|awk '{ print $3 }'|sort|uniq); do
    wget "${url}$i" -O "$i"                                                                
done
dir="$(echo "libguestfs-${latest}"|cut -d- -f1-2)"
rm -rf $dir
dpkg-source -x "libguestfs_${latest}.dsc"
cd $dir
echo y|mk-build-deps -i || exit
rm -f libguestfs-build-deps_${latest}_amd64.deb
debuild || exit
apt-get purge -y --auto-remove libguestfs-build-deps
cd .. && \
packages="$(grep "^Package" debian/control |cut -d" " -f2)" \
installedpkgs="$(echo "$packages"|sed -e s#" $"#""#g -e s#" "#" -e "#g)|awk '{ print $2 }'|cut -d: -f1)" \
upgradepkgs="$(eval dpkg -l |grep -e $(echo "${installedpkgs}"|tr "\n" " "|sed s#" "#"_${latest}\*deb "#g)" && \
eval dpkg --install libguestfs-tools_${latest}_amd64.deb ${upgradepkgs};
