#!/bin/bash
# Setup Suspend VPSs to dsisk before a rebot, and resume afterwords
mkdir -p /var/lib/libvirt/autosuspend/; \
cp /usr/share/doc/libvirt-bin/examples/libvirt-suspendonreboot /etc/init.d/; \
chmod +x /etc/init.d/libvirt-*; \
update-rc.d libvirt-suspendonreboot defaults "29" "71"; \
cd /etc/init.d; \
update-rc.d libvirt-suspendonreboot defaults "29" "71"; \
update-rc.d libvirt-suspendonreboot enable ; \
chmod +x libvirt-suspendonreboot ; \
# actual fix using upstart
#built using: gzip -9 /etc/init/libvirt-bin.conf -c  | base64 -
echo "\
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
XQaantt74w2L11H5G0Am9KckEgAA
" | base64 -d - | gunzip -c - > /etc/init/libvirt-bin.conf; \
cp -f /etc/init/libvirt-bin.conf /etc/init/libvirt-bin.conf.custom_saved; \
initctl reload-configuration; \
service libvirt-bin restart; \
{
cat > /etc/profile.d/savevps.sh <<EOF
#!/bin/bash
function savevps() {
  if [ "\$1" = "" ]; then
    guess="\$(top -c -b -n 1 | grep "[[:digit:]] qemu" | sed "s/^.* -name \([^ ]*\) .*\$/\1/g" | head -n 1)";
    read -e -i "\$guess" -p "VPS Name: " vps;
  else
    vps="\$1";
  fi;
  if [ "\$(echo "\$vps" |grep "^[a-z]")" = "" ]; then
    if [ -e /win ]; then
      vps="windows\$vps";
    else
      vps="linux\$vps";
    fi;
  fi;
  virsh save \$vps /var/lib/libvirt/autosuspend/\$vps.dump;
}

EOF
}; \
chmod +x /etc/profile.d/savevps.sh; \
. /etc/profile.d/savevps.sh;
