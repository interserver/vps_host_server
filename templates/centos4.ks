#version=RHEL7
# System authorization information
auth --enableshadow --passalgo=sha512
# Use CDROM installation media
cdrom
# Use graphical install
graphical
eula --agreed
reboot
# Keyboard layouts
keyboard --vckeymap=us --xlayouts='us'
# System language
lang en_US.UTF-8
# Root password
rootpw --iscrypted $6$HqS6tEQZKMzZXRqT$T1dmqcISnQQtVR.u/Sh56jKr75bS2JwZe20PddB/Ab1kT3391igLp8AA5VX7vlhbl9WJHtMKyMlrxeHB7ghjK1
# System timezone
timezone Europe/Belgrade --isUtc
# Run the Setup Agent on first boot
firstboot --disable
ignoredisk --only-use=vda
zerombr
clearpart --all --initlabel --drives=vda

# System bootloader configuration
#Partition clearing information
part /boot --fstype="ext4" --ondisk=vda --size=500 --asprimary
part pv.16 --ondisk=vda --grow --size=9739 --asprimary
volgroup vg00 --pesize=4096 pv.16
logvol swap --fstype="swap" --size=500 --name=swap --vgname=vg00
logvol / --fstype="ext4" --size=8230 --name=root --vgname=vg00
logvol /home --fstype="ext4" --size=300 --name=home --vgname=vg00
bootloader --location=mbr --boot-drive=vda

user --groups=wheel --name=admin1 --password=$6$HqS6tEQZKMzZXRqT$T1dmqcISnQQtVR.u/Sh56jKr75bS2JwZe20PddB/Ab1kT3391igLp8AA5VX7vlhbl9WJHtMKyMlrxeHB7ghjK1 --iscrypted --gecos="admin1"

%packages
@core
@base
@FTP Server
@DNS Name Server
@Web Server
kexec-tools
%end
