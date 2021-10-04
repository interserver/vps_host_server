# ProVirted

## About

Easy management of Virtualization technologies including KVM, OpenVZ and Virtuozzo.

## TODO

* add **self-update** command for downloading the latest phar and replacing it
* store vzid only in the vzid field not hostname for kvm
* add **internals** command with acess to internal methods making it easier to use the tool to create scripts
* merge **change-hostname** and **change-timezone** into **update**
* merge **insert-cd**, **eject-cd**, **enable-cd**, **disable-cd** commands into a single **cd** command mabye with subdommands
* add bash/zsh completion suggestions for ip fields (except client ip) having it show the ips on the host server excluding ones in use
* add escapeshellarg() calls around any vars being passed through a exec type call
* add **install** command - Installs PreRequisites, Configures Software for our setup
* add **config** command - Management of the various settings
* add server option to **test** command to perform various self diagnostics to check on the health and prepairedness of the system
* possibly utilize virt-resize in **update** call instead of qemu-img resize
* fix **reset-password** command adding in detection of windows and skipping if not
* merge **reset-password** into **update**
* fix the restore script to work with kvmv2 os.qcow2 files
* split off into its own github org/repo
* create public website on github
* add wiki entries
* add openvz support
* add lxc support
* remove unused scripts
* remove reliance on local scripts

buildebtablesrules
run_buildebtables.sh
tclimit

create_libvirt_storage_pools.sh
vps_get_image.sh
vps_kvm_lvmcreate.sh
vps_kvm_lvmresize.sh
vps_swift_restore.sh

vps_kvm_password_manual.php
vps_kvm_setup_password_clear.sh

vps_kvm_screenshot.sh
vps_kvm_screenshot_swift.sh
vps_refresh_vnc.sh
vps_kvm_setup_vnc.sh
vps_virtuozzo_setup_vnc.sh


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
* **update** Change the hd, cpu, memory, password, etc of a Virtual Machine.
* **reset-password** Resets/Clears a Password on a Virtual Machine.
* **add-ip** Adds an IP Address to a Virtual Machine.
* **remove-ip** Removes an IP Address from a Virtual Machine.
* **enable-cd** Enable the CD-ROM and optionally Insert a CD in a Virtual Machine.
* **disable-cd** Disable the CD-ROM in a Virtual Machine.
* **eject-cd** Eject a CD from a Virtual Machine.
* **insert-cd** Load a CD image into an existing CD-ROM in a Virtual Machine.
* **test** Perform various self diagnostics to check on the health and prepairedness of the system.

### Debugging

you can add -v to increase verbosity by 1 and see all the commands being run, or a second time to also see the output and exit status of each command

## Building

### Setup Bash Completion

```bash
php provirted.php bash --bind provirted --program provirted > /etc/bash_completion.d/provirted
```

### Compile the code into a PHAR

```bash
php provirted.php archive --composer=composer.json --app-bootstrap --executable --compress=gz provirted.phar
```

Some Install Code
```bash
cd /root/cpaneldirect && git pull --all && /bin/cp -fv /root/cpaneldirect/cli/provirted_completion /etc/bash_completion.d/ && if [ -e /etc/apt ]; then apt-get update &&  apt-get autoremove -y --purge && apt-get dist-upgrade -y && apt-get autoremove -y --purge && apt-get clean && if [ "$(php -v|head -n 1|cut -c5)" = 7 ]; then exit; fi; else yum update -y && if [ "$(php -v|head -n 1|cut -c5)" = 7 ]; then exit; fi; fi
```

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
