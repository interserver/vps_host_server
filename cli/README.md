# ProVirted

## About

Easy management of Virtualization technologies including KVM, OpenVZ and Virtuozzo.

## TODO

* add bash/zsh completion suggestions for ip fields (except client ip) having it show the ips on the host server excluding ones in use
* add escapeshellarg() calls around any vars being passed through a exec type call
* add **server-setup** command - Installs PreRequisites, Configures Software for our setup
* add **config** command - Management of the various settings
* add **server-test** command - Perform various self diagnostics to check on the health and prepairedness of the system
* possibly utilize virt-resize in update-hdsize call instead of qemu-img resize
* fix reset-password command adding in detection of windows and skipping if not
* fix the restore script to work with kvmv2 os.qcow2 files

## Commands

* **create** Creates a Virtual Machine.
* **destroy** Destroys a Virtual Machine.
* **enable** Enables a Virtual Machine.
* **delete** Deletes a Virtual Machine.
* **backup** Creates a Backup of a Virtual Machine.
* **restore** Restores a Virtual Machine from Backup.
* **stop** Stops a Virtual Machine.
* **start** Starts a Virtual Machine.
* **restart** Restarts a Virtual Machine.
* **block-smtp** Blocks SMTP on a Virtual Machine.
* **change-hostname** Change Hostname of a Virtual Machine.
* **change-timezone** Change Timezone of a Virtual Machine.
* **setup-vnc** Setup VNC Allowed IP on a Virtual Machine.
* **update-hdsize** Change the HD Size of a Virtual Machine.
* **reset-password** Resets/Clears a Password on a Virtual Machine.
* **add-ip** Adds an IP Address to a Virtual Machine.
* **remove-ip** Removes an IP Address from a Virtual Machine.
* **enable-cd** Enable the CD-ROM and optionally Insert a CD in a Virtual Machine.
* **disable-cd** Disable the CD-ROM in a Virtual Machine.
* **eject-cd** Eject a CD from a Virtual Machine.
* **insert-cd** Load a CD image into an existing CD-ROM in a Virtual Machine.
* **test** Perform various self diagnostics to check on the health and prepairedness of the system.

## Developer Links

* [c9s/CLIFramework](https://github.com/c9s/CLIFramework) CLIFramework GitHub repo
* [c9s/CLIFramework/wiki](https://github.com/c9s/CLIFramework/wiki) CLIFramework Wiki
* [walkor/webman](https://github.com/walkor/webman) Webman GitHub repo
* [workerman.net/doc/webman](https://www.workerman.net/doc/webman) Webman Docs
* [thephpleague/climate](https://github.com/thephpleague/climate) PHP's best friend for the terminal.
* [climate.thephpleague.com/](https://climate.thephpleague.com/) CLImate Docs
* [kylekatarnls/simple-cli](https://github.com/kylekatarnls/simple-cli) A simple cli framework
* [inhere/php-console](https://github.com/inhere/php-console) PHP CLI application library, provide console argument parse, console controller/command run, color style, user interactive, format information show and more.
* [inhere/php-console/wiki](https://github.com/inhere/php-console/wiki) php-console Wiki
* [jc21/clitable](https://github.com/jc21/clitable) Colored CLI Table Output for PHP
* [php-school/cli-menu](https://github.com/php-school/cli-menu) Build beautiful PHP CLI menus. Simple yet Powerful. Expressive DSL.


## Building

### Setup Bash Completion

```bash
php provirted.php bash --bind provirted --program provirted > /etc/bash_completion.d/provirted
```

### Compile the code into a PHAR

Not sure yet if I want to just go with the php file and source tree or a single phar file.  A phar would probablybe simpler.

I can generate a PHAR with the following command:
```bash
php provirted.php --debug archive --app-bootstrap --executable --no-compress provirted.phar
```

## Testing

Here is a breakdown of the VPS type's and what distro/version combinations are used on each and how many. If we test each of the servers listed below of a given type, then we have tested it on every distro/version we use accross all servers of that type.

```mysql
(root@localhost:my) mysql> select vps_name as sample_host,st_name as type,vps_distro as distro,ifnull(null,substring(vps_distro_version, 1, locate('.', vps_distro_version) - 1)) as version, count(vps_id) as count from vps_masters left join vps_master_details using (vps_id)
left join service_types on st_id=vps_type group by vps_type,vps_distro,ifnull(null,substring(vps_distro_version, 1, locate('.', vps_distro_version) - 1)) order by st_name,vps_distro,ifnull(null,substring(vps_distro_version, 1, locate('.', vps_distro_version) - 1));
+----------------+-----------------+-----------+---------+-------+
| sample_host    | type            | distro    | version | count |
+----------------+-----------------+-----------+---------+-------+
| HyperV-dev     | Hyper-V         | Windows   | NULL    |    92 |
| KVM1004        | KVM Linux       | CentOS    | 7       |     4 |
| Intvps4        | KVM Linux       | Ubuntu    | 20      |     1 |
| KVM3.ny4       | KVM Windows     | Ubuntu    | 18      |     1 |
| KVM27          | KVMv2           | Ubuntu    | 18      |    34 |
| KVM3           | KVMv2           | Ubuntu    | 20      |    36 |
| Storage-kvm100 | KVMv2 Storage   | Ubuntu    | 20      |    11 |
| KVM28          | KVMv2 Windows   | Ubuntu    | 18      |    13 |
| KVM12          | KVMv2 Windows   | Ubuntu    | 20      |    16 |
| Lxc            | LXC             | Ubuntu    | 20      |     2 |
| OpenVZ2        | OpenVZ          | CentOS    | 6       |    52 |
| SSDOpenVZ2     | SSD OpenVZ      | CentOS    | 6       |    10 |
| SSDOpenVZ1     | SSD Virtuozzo 7 | Virtuozzo | 7       |     4 |
| IntVPS3        | Virtuozzo 7     | CentOS    | 6       |     1 |
| OpenVZ1        | Virtuozzo 7     | Virtuozzo | 7       |    72 |
+----------------+-----------------+-----------+---------+-------+
15 rows in set (0.00 sec)
```

## Terminal Recording

### asciinema

* [https://github.com/asciinema/asciinema](https://github.com/asciinema/asciinema)
* [https://asciinema.org/docs/how-it-works](https://asciinema.org/docs/how-it-works)

```bash
apt-get install asciinema
asciinema rec mydemo.cast
asciinema play mydemo.cast
asciinema upload mydemo.cast
```

### terminalizer

* [https://github.com/faressoft/terminalizer](https://github.com/faressoft/terminalizer)
* [https://terminalizer.com/](https://terminalizer.com/)
* [https://terminalizer.com/docs](https://terminalizer.com/docs)

```bash
npm install -g terminalizer
terminalizer record mydemo
terminalizer render mydemo
terminalizer play mydemo
```

### termtosvg

* [https://github.com/nbedos/termtosvg](https://github.com/nbedos/termtosvg)
* [https://github.com/nbedos/termtosvg/blob/develop/man/termtosvg.md](https://github.com/nbedos/termtosvg/blob/develop/man/termtosvg.md)
* [https://nbedos.github.io/termtosvg/](https://nbedos.github.io/termtosvg/)

```bash
pip3 install termtosvg
termtosvg mydemo.svcg
termtosvg record mydemo.svg
termtosvg render mydemo.cast mydemo.svg
```

## Fixing CentOS 6/7 Hosts

This fixs several issues with CentOS 6 and CentOS 7 servers

```bash
if [ -e /etc/redhat-release ]; then
  rhver="$(cat /etc/redhat-release |sed s#"^.*release \([0-9][^ ]*\).*$"#"\1"#g)"
  rhmajor="$(echo "${rhver}"|cut -c1)"
  if [ ${rhmajor} -lt 7 ]; then
    if [ "$rhver" = "6.108" ]; then
      rhver="6.10";
    fi;
    sed -i "/^mirrorlist/s/^/#/;/^#baseurl/{s/#//;s/mirror.centos.org\/centos\/$releasever/vault.centos.org\/${rhver}/}" /etc/yum.repos.d/*B*;
  fi;
  if [ ${rhmajor} -eq 6 ]; then
    yum install epel-release yum-utils -y;
    yum install http://rpms.remirepo.net/enterprise/remi-release-6.rpm -y;
    yum-config-manager --enable remi-php73;
    yum update -y;
  elif [ ${rhmajor} -eq 7 ]; then
    yum install epel-release yum-utils -y;
    yum install http://rpms.remirepo.net/enterprise/remi-release-7.rpm -y;
    yum-config-manager --enable remi-php74;
    yum update -y;
    # for Virtuozzo 7
    yum install php74 php74-php-{bcmath,cli,pdo,devel,gd,intl,json,mbstring,opcache,pear,pecl-ev,pecl-event,pecl-eio,pecl-inotify,zstd,xz,xml,xmlrpc,sodium,soap,snmp,process,pecl-zip,pecl-xattr,pecl-yaml,pecl-ssh2,mysqlnd,pecl-igbinary,pecl-imagick} -y
  fi;
fi
```

## Data Structures Found In Code

### Virtuozzo

```json
{
  "ID": "ccefa40c-5c72-4e17-94c7-d68034a1c1a5",
  "EnvID": "132694",
  "Name": "132694",
  "Description": "",
  "Type": "CT",
  "State": "running",
  "OS": "centos7",
  "Template": "no",
  "Uptime": "797400",
  "Home": "/vz/private/132694",
  "Backup path": "",
  "Owner": "root",
  "GuestTools": {
    "state": "possibly_installed"
  },
  "GuestTools autoupdate": "on",
  "Autostart": "on",
  "Autostop": "suspend",
  "Autocompact": "on",
  "Boot order": "",
  "EFI boot": "off",
  "Allow select boot device": "off",
  "External boot device": "",
  "Remote display": {
    "mode": "off",
    "address": "0.0.0.0"
  },
  "Remote display state": "stopped",
  "Hardware": {
    "cpu": {
      "sockets": 1,
      "cpus": 1,
      "cores": 1,
      "VT-x": true,
      "hotplug": true,
      "accl": "high",
      "mode": "64",
      "cpuunits": 1500,
      "ioprio": 4
    },
    "memory": {
      "size": "1024Mb",
      "hotplug": true
    },
    "video": {
      "size": "0Mb",
      "3d acceleration": "off",
      "vertical sync": "yes"
    },
    "memory_guarantee": {
      "auto": true
    },
    "hdd0": {
      "enabled": true,
      "port": "scsi:0",
      "image": "/vz/private/132694/root.hdd",
      "type": "expanded",
      "size": "25390Mb",
      "mnt": "/",
      "subtype": "virtio-scsi"
    },
    "venet0": {
      "enabled": true,
      "type": "routed",
      "ips": "216.158.239.189 "
    }
  },
  "Features": "",
  "Disabled Windows logo": "on",
  "Nested virtualization": "off",
  "Offline management": {
    "enabled": false
  },
  "Hostname": "t3.netfresn.net",
  "DNS Servers": "8.8.8.8 64.20.34.50",
  "Search Domains": "interserver.net",
  "High Availability": {
    "enabled": "yes",
    "prio": 0
  }
}
```

### KVM

virsh dumlxml

```xml
<domain type='kvm' id='1'>
  <name>windows81042</name>
  <uuid>9b5b4562-8515-4372-93ab-168c65df4c2d</uuid>
  <memory unit='KiB'>2120704</memory>
  <currentMemory unit='KiB'>2120704</currentMemory>
  <vcpu placement='static'>1</vcpu>
  <resource>
    <partition>/machine</partition>
  </resource>
  <os>
    <type arch='x86_64' machine='pc-i440fx-2.11'>hvm</type>
    <boot dev='cdrom'/>
    <boot dev='hd'/>
  </os>
  <features>
    <acpi/>
    <apic/>
    <pae/>
    <hap state='on'/>
  </features>
  <cpu mode='custom' match='exact' check='full'>
    <model fallback='forbid'>Broadwell-IBRS</model>
    <vendor>Intel</vendor>
    <feature policy='require' name='vme'/>
    <feature policy='require' name='ss'/>
    <feature policy='require' name='vmx'/>
    <feature policy='require' name='f16c'/>
    <feature policy='require' name='rdrand'/>
    <feature policy='require' name='hypervisor'/>
    <feature policy='require' name='arat'/>
    <feature policy='require' name='tsc_adjust'/>
    <feature policy='require' name='umip'/>
    <feature policy='require' name='md-clear'/>
    <feature policy='require' name='stibp'/>
    <feature policy='require' name='arch-capabilities'/>
    <feature policy='require' name='ssbd'/>
    <feature policy='require' name='xsaveopt'/>
    <feature policy='require' name='pdpe1gb'/>
    <feature policy='require' name='abm'/>
    <feature policy='require' name='ibpb'/>
    <feature policy='require' name='ibrs'/>
    <feature policy='require' name='amd-stibp'/>
    <feature policy='require' name='amd-ssbd'/>
    <feature policy='require' name='skip-l1dfl-vmentry'/>
    <feature policy='require' name='pschange-mc-no'/>
  </cpu>
  <clock offset='timezone' timezone='America/New_York'/>
  <on_poweroff>destroy</on_poweroff>
  <on_reboot>restart</on_reboot>
  <on_crash>restart</on_crash>
  <devices>
    <emulator>/usr/bin/kvm</emulator>
    <disk type='file' device='cdrom'>
      <driver name='qemu'/>
      <target dev='hda' bus='ide'/>
      <readonly/>
      <alias name='ide0-0-0'/>
      <address type='drive' controller='0' bus='0' target='0' unit='0'/>
    </disk>
    <disk type='file' device='disk'>
      <driver name='qemu' type='qcow2' cache='writeback' discard='unmap'/>
      <source file='/vz/windows81042/os.qcow2' index='1'/>
      <backingStore/>
      <target dev='vda' bus='virtio'/>
      <alias name='virtio-disk0'/>
      <address type='pci' domain='0x0000' bus='0x00' slot='0x07' function='0x0'/>
    </disk>
    <controller type='ide' index='0'>
      <alias name='ide'/>
      <address type='pci' domain='0x0000' bus='0x00' slot='0x01' function='0x1'/>
    </controller>
    <controller type='scsi' index='0' model='virtio-scsi'>
      <driver queues='1'/>
      <alias name='scsi0'/>
      <address type='pci' domain='0x0000' bus='0x00' slot='0x06' function='0x0'/>
    </controller>
    <controller type='usb' index='0' model='piix3-uhci'>
      <alias name='usb'/>
      <address type='pci' domain='0x0000' bus='0x00' slot='0x01' function='0x2'/>
    </controller>
    <controller type='pci' index='0' model='pci-root'>
      <alias name='pci.0'/>
    </controller>
    <interface type='bridge'>
      <mac address='52:54:00:26:45:ff'/>
      <source bridge='br0'/>
      <target dev='vnet0'/>
      <model type='virtio'/>
      <alias name='net0'/>
      <address type='pci' domain='0x0000' bus='0x00' slot='0x03' function='0x0'/>
    </interface>
    <serial type='pty'>
      <source path='/dev/pts/0'/>
      <target type='isa-serial' port='0'>
        <model name='isa-serial'/>
      </target>
      <alias name='serial0'/>
    </serial>
    <console type='pty' tty='/dev/pts/0'>
      <source path='/dev/pts/0'/>
      <target type='serial' port='0'/>
      <alias name='serial0'/>
    </console>
    <input type='tablet' bus='usb'>
      <alias name='input0'/>
      <address type='usb' bus='0' port='1'/>
    </input>
    <input type='mouse' bus='ps2'>
      <alias name='input1'/>
    </input>
    <input type='keyboard' bus='ps2'>
      <alias name='input2'/>
    </input>
    <graphics type='vnc' port='5900' autoport='yes' listen='127.0.0.1' keymap='en-us'>
      <listen type='address' address='127.0.0.1'/>
    </graphics>
    <graphics type='spice' port='5901' autoport='yes' listen='127.0.0.1'>
      <listen type='address' address='127.0.0.1'/>
      <image compression='auto_glz'/>
      <streaming mode='filter'/>
      <mouse mode='client'/>
      <clipboard copypaste='yes'/>
    </graphics>
    <sound model='ac97'>
      <alias name='sound0'/>
      <address type='pci' domain='0x0000' bus='0x00' slot='0x04' function='0x0'/>
    </sound>
    <video>
      <model type='vga' vram='16384' heads='1' primary='yes'/>
      <alias name='video0'/>
      <address type='pci' domain='0x0000' bus='0x00' slot='0x02' function='0x0'/>
    </video>
    <memballoon model='virtio'>
      <alias name='balloon0'/>
      <address type='pci' domain='0x0000' bus='0x00' slot='0x05' function='0x0'/>
    </memballoon>
  </devices>
  <seclabel type='dynamic' model='apparmor' relabel='yes'>
    <label>libvirt-9b5b4562-8515-4372-93ab-168c65df4c2d</label>
    <imagelabel>libvirt-9b5b4562-8515-4372-93ab-168c65df4c2d</imagelabel>
  </seclabel>
  <seclabel type='dynamic' model='dac' relabel='yes'>
    <label>+64055:+108</label>
    <imagelabel>+64055:+108</imagelabel>
  </seclabel>
</domain>
```

converted to array using XmlToArray class
```php
[                                                                                                                                                                                                                                                                   [463/95837]
 "domain" => [
   "name" => "windows81042",
   "uuid" => "9b5b4562-8515-4372-93ab-168c65df4c2d",
   "memory" => "2120704",
   "memory_attr" => [
     "unit" => "KiB",
   ],
   "currentMemory" => "2120704",
   "currentMemory_attr" => [
     "unit" => "KiB",
   ],
   "vcpu" => "1",
   "vcpu_attr" => [
     "placement" => "static",
   ],
   "resource" => [
     "partition" => "/machine",
   ],
   "os" => [
     "type" => "hvm",
     "type_attr" => [
       "arch" => "x86_64",
       "machine" => "pc-i440fx-2.11",
     ],
     "boot" => [
       0 => [],
       1 => [],
       "0_attr" => [
         "dev" => "cdrom",
       ],
       "1_attr" => [
         "dev" => "hd",
       ],
     ],
   ],
   "features" => [
     "acpi" => [],
     "apic" => [],
     "pae" => [],
     "hap" => [],
     "hap_attr" => [
       "state" => "on",
     ],
   ],
   "cpu" => [
     "model" => "Broadwell-IBRS",
     "model_attr" => [
       "fallback" => "forbid",
     ],
     "vendor" => "Intel",
     "feature" => [
       0 => [],
       1 => [],
       "0_attr" => [
         "policy" => "require",
         "name" => "vme",
       ],
       "1_attr" => [
         "policy" => "require",
         "name" => "ss",
       ],
       2 => [],
       "2_attr" => [
         "policy" => "require",
         "name" => "vmx",
       ],
       3 => [],
       "3_attr" => [
         "policy" => "require",
         "name" => "f16c",
       ],
       4 => [],
       "4_attr" => [
         "policy" => "require",
         "name" => "rdrand",
       ],
       5 => [],
       "5_attr" => [
         "policy" => "require",
         "name" => "hypervisor",
       ],
       6 => [],
       "6_attr" => [
         "policy" => "require",
         "name" => "arat",
       ],
       7 => [],
       "7_attr" => [
         "policy" => "require",
         "name" => "tsc_adjust",
       ],
       8 => [],
       "8_attr" => [
         "policy" => "require",
         "name" => "umip",
       ],
       9 => [],
       "9_attr" => [
         "policy" => "require",
         "name" => "md-clear",
       ],
       10 => [],
       "10_attr" => [
         "policy" => "require",
         "name" => "stibp",
       ],
       11 => [],
       "11_attr" => [
         "policy" => "require",
         "name" => "arch-capabilities",
       ],
       12 => [],
       "12_attr" => [
         "policy" => "require",
         "name" => "ssbd",
       ],
       13 => [],
       "13_attr" => [
         "policy" => "require",
         "name" => "xsaveopt",
       ],
       14 => [],
       "14_attr" => [
         "policy" => "require",
         "name" => "pdpe1gb",
       ],
       15 => [],
       "15_attr" => [
         "policy" => "require",
         "name" => "abm",
       ],
       16 => [],
       "16_attr" => [
         "policy" => "require",
         "name" => "ibpb",
       ],
       17 => [],
       "17_attr" => [
         "policy" => "require",
         "name" => "ibrs",
       ],
       18 => [],
       "18_attr" => [
         "policy" => "require",
         "name" => "amd-stibp",
       ],
       19 => [],
       "19_attr" => [
         "policy" => "require",
         "name" => "amd-ssbd",
       ],
       20 => [],
       "20_attr" => [
         "policy" => "require",
         "name" => "skip-l1dfl-vmentry",
       ],
       21 => [],
       "21_attr" => [
         "policy" => "require",
         "name" => "pschange-mc-no",
       ],
     ],
   ],
   "cpu_attr" => [
     "mode" => "custom",
     "match" => "exact",
     "check" => "full",
   ],
   "clock" => [],
   "clock_attr" => [
     "offset" => "timezone",
     "timezone" => "America/New_York",
   ],
   "on_poweroff" => "destroy",
   "on_reboot" => "restart",
   "on_crash" => "restart",
   "devices" => [
     "emulator" => "/usr/bin/kvm",
     "disk" => [
       0 => [
         "driver" => [],
         "driver_attr" => [
           "name" => "qemu",
         ],
         "target" => [],
         "target_attr" => [
           "dev" => "hda",
           "bus" => "ide",
         ],
         "readonly" => [],
         "alias" => [],
         "alias_attr" => [
           "name" => "ide0-0-0",
         ],
         "address" => [],
         "address_attr" => [
           "type" => "drive",
           "controller" => "0",
           "bus" => "0",
           "target" => "0",
           "unit" => "0",
         ],
       ],
       1 => [
         "driver" => [],
         "driver_attr" => [
           "name" => "qemu",
           "type" => "qcow2",
           "cache" => "writeback",
           "discard" => "unmap",
         ],
         "source" => [],
         "source_attr" => [
           "file" => "/vz/windows81042/os.qcow2",
           "index" => "1",
         ],
         "backingStore" => [],
         "target" => [],
         "target_attr" => [
           "dev" => "vda",
           "bus" => "virtio",
         ],
         "alias" => [],
         "alias_attr" => [
           "name" => "virtio-disk0",
         ],
         "address" => [],
         "address_attr" => [
           "type" => "pci",
           "domain" => "0x0000",
           "bus" => "0x00",
           "slot" => "0x07",
           "function" => "0x0",
         ],
       ],
       "0_attr" => [
         "type" => "file",
         "device" => "cdrom",
       ],
     ],
     "controller" => [
       0 => [
         "alias" => [],
         "alias_attr" => [
           "name" => "ide",
         ],
         "address" => [],
         "address_attr" => [
           "type" => "pci",
           "domain" => "0x0000",
           "bus" => "0x00",
           "slot" => "0x01",
           "function" => "0x1",
         ],
       ],
       1 => [
         "driver" => [],
         "driver_attr" => [
           "queues" => "1",
         ],
         "alias" => [],
         "alias_attr" => [
           "name" => "scsi0",
         ],
         "address" => [],
         "address_attr" => [
           "type" => "pci",
           "domain" => "0x0000",
           "bus" => "0x00",
           "slot" => "0x06",
           "function" => "0x0",
         ],
       ],
       "0_attr" => [
         "type" => "ide",
         "index" => "0",
       ],
       2 => [
         "alias" => [],
         "alias_attr" => [
           "name" => "usb",
         ],
         "address" => [],
         "address_attr" => [
           "type" => "pci",
           "domain" => "0x0000",
           "bus" => "0x00",
           "slot" => "0x01",
           "function" => "0x2",
         ],
       ],
       3 => [
         "alias" => [],
         "alias_attr" => [
           "name" => "pci.0",
         ],
       ],
     ],
     "interface" => [
       "mac" => [],
       "mac_attr" => [
         "address" => "52:54:00:26:45:ff",
       ],
       "source" => [],
       "source_attr" => [
         "bridge" => "br0",
       ],
       "target" => [],
       "target_attr" => [
         "dev" => "vnet0",
       ],
       "model" => [],
       "model_attr" => [
         "type" => "virtio",
       ],
       "alias" => [],
       "alias_attr" => [
         "name" => "net0",
       ],
       "address" => [],
       "address_attr" => [
         "type" => "pci",
         "domain" => "0x0000",
         "bus" => "0x00",
         "slot" => "0x03",
         "function" => "0x0",
       ],
     ],
     "interface_attr" => [
       "type" => "bridge",
     ],
     "serial" => [
       "source" => [],
       "source_attr" => [
         "path" => "/dev/pts/0",
       ],
       "target" => [
         "model" => [],
         "model_attr" => [
           "name" => "isa-serial",
         ],
       ],
       "target_attr" => [
         "type" => "isa-serial",
         "port" => "0",
       ],
       "alias" => [],
       "alias_attr" => [
         "name" => "serial0",
       ],
     ],
     "serial_attr" => [
       "type" => "pty",
     ],
     "console" => [
       "source" => [],
       "source_attr" => [
         "path" => "/dev/pts/0",
       ],
       "target" => [],
       "target_attr" => [
         "type" => "serial",
         "port" => "0",
       ],
       "alias" => [],
       "alias_attr" => [
         "name" => "serial0",
       ],
     ],
     "console_attr" => [
       "type" => "pty",
       "tty" => "/dev/pts/0",
     ],
     "input" => [
       0 => [
         "alias" => [],
         "alias_attr" => [
           "name" => "input0",
         ],
         "address" => [],
         "address_attr" => [
           "type" => "usb",
           "bus" => "0",
           "port" => "1",
         ],
       ],
       1 => [
         "alias" => [],
         "alias_attr" => [
           "name" => "input1",
         ],
       ],
       "0_attr" => [
         "type" => "tablet",
         "bus" => "usb",
       ],
       2 => [
         "alias" => [],
         "alias_attr" => [
           "name" => "input2",
         ],
       ],
     ],
     "graphics" => [
       0 => [
         "listen" => [],
         "listen_attr" => [
           "type" => "address",
           "address" => "127.0.0.1",
         ],
       ],
       1 => [
         "listen" => [],
         "listen_attr" => [
           "type" => "address",
           "address" => "127.0.0.1",
         ],
         "image" => [],
         "image_attr" => [
           "compression" => "auto_glz",
         ],
         "streaming" => [],
         "streaming_attr" => [
           "mode" => "filter",
         ],
         "mouse" => [],
         "mouse_attr" => [
           "mode" => "client",
         ],
         "clipboard" => [],
         "clipboard_attr" => [
           "copypaste" => "yes",
         ],
       ],
       "0_attr" => [
         "type" => "vnc",
         "port" => "5900",
         "autoport" => "yes",
         "listen" => "127.0.0.1",
         "keymap" => "en-us",
       ],
     ],
     "sound" => [
       "alias" => [],
       "alias_attr" => [
         "name" => "sound0",
       ],
       "address" => [],
       "address_attr" => [
         "type" => "pci",
         "domain" => "0x0000",
         "bus" => "0x00",
         "slot" => "0x04",
         "function" => "0x0",
       ],
     ],
     "sound_attr" => [
       "model" => "ac97",
     ],
     "video" => [
       "model" => [],
       "model_attr" => [
         "type" => "vga",
         "vram" => "16384",
         "heads" => "1",
         "primary" => "yes",
       ],
       "alias" => [],
       "alias_attr" => [
         "name" => "video0",
       ],
       "address" => [],
       "address_attr" => [
         "type" => "pci",
         "domain" => "0x0000",
         "bus" => "0x00",
         "slot" => "0x02",
         "function" => "0x0",
       ],
     ],
     "memballoon" => [
       "alias" => [],
       "alias_attr" => [
         "name" => "balloon0",
       ],
       "address" => [],
       "address_attr" => [
         "type" => "pci",
         "domain" => "0x0000",
         "bus" => "0x00",
         "slot" => "0x05",
         "function" => "0x0",
       ],
     ],
     "memballoon_attr" => [
       "model" => "virtio",
     ],
   ],
   "seclabel" => [
     0 => [
       "label" => "libvirt-9b5b4562-8515-4372-93ab-168c65df4c2d",
       "imagelabel" => "libvirt-9b5b4562-8515-4372-93ab-168c65df4c2d",
     ],
     1 => [
       "label" => "+64055:+108",
       "imagelabel" => "+64055:+108",
     ],
     "0_attr" => [
       "type" => "dynamic",
       "model" => "apparmor",
       "relabel" => "yes",
     ],
   ],
 ],
 "domain_attr" => [
   "type" => "kvm",
   "id" => "1",
 ],
]
```

