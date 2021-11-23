# ProVirted

## About

Easy management of Virtualization technologies including KVM, OpenVZ and Virtuozzo.

## TODO

* Add template exists checks to the create code
* Check your passwords beginning with hyphens interfere with the option parsing and that if a double dash will resolve the issue
* store vzid only in the vzid field not hostname for kvm
  * it looks like we can grab information about the vm by using virt-inspector --no-applications -d <vzid> to get a xml formatted output of basic os info including hostnmae
* fix **reset-password** command adding in detection of windows and skipping if not
* possibly utilize virt-resize in **update** call instead of qemu-img resize
* add bash/zsh completion suggestions for ip fields (except client ip) having it show the ips on the host server excluding ones in use
* add escapeshellarg() calls around any vars being passed through a exec type call
* fix the restore script to work with kvmv2 os.qcow2 files
* split off into its own github org/repo [provirted/provirted](https://github.com/provirted/provirted.github.io)
  * create public website on github [https://github.com/provirted/provirted.github.io](provirted/provirted.github.io)
  * add wiki entries
* add lxc support  [https://linuxcontainers.org/lxd/docs/master/](LXD Docs)
* add **self-update** command for downloading the latest phar and replacing it
* add **install** command - Installs PreRequisites, Configures Software for our setup
* add **config** command - Management of the various settings
* add server option to **test** command to perform various self diagnostics to check on the health and prepairedness of the system
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
* **update** Change the hd, cpu, memory, password, etc of a Virtual Machine.
* **reset-password** Resets/Clears a Password on a Virtual Machine.
* **add-ip** Adds an IP Address to a Virtual Machine.
* **remove-ip** Removes an IP Address from a Virtual Machine.
* **cd** CD-ROM management functionality
* **test** Perform various self diagnostics to check on the health and prepairedness of the system.

### Debugging

you can add -v to increase verbosity by 1 and see all the commands being run, or a second time to also see the output and exit status of each command

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


## Dev Notes/Code

Fixing CentOS 6/7 Hosts
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
    yum install php74 php74-php-{bcmath,cli,pdo,devel,gd,intl,json,mbstring} \
      php74-php-{opcache,pear,pecl-ev,pecl-event,pecl-eio,pecl-inotify,xz,xml} \
      php74-php-{xmlrpc,sodium,soap,snmp,process,pecl-zip,pecl-xattr} \
      php74-php-{pecl-yaml,pecl-ssh2,mysqlnd,pecl-igbinary,pecl-imagick} -y;
    for i in /opt/remi/php74/root/usr/bin/*; do
      ln -s "$i" /usr/local/bin/;
    done;
  fi;
fi
```

Updating the host
```bash
cd /root/cpaneldirect && git pull --all && /bin/cp -fv /root/cpaneldirect/cli/provirted_completion /etc/bash_completion.d/ && if [ -e /etc/apt ]; then apt-get update &&  apt-get autoremove -y --purge && apt-get dist-upgrade -y && apt-get autoremove -y --purge && apt-get clean && if [ "$(php -v|head -n 1|cut -c5)" = 7 ]; then exit; fi; else yum update -y && if [ "$(php -v|head -n 1|cut -c5)" = 7 ]; then exit; fi; fi


ssh my@mynew php /home/my/scripts/vps/qs_list.php all|grep -v 'Now using' > servers.csv ; \
ssh my@mynew php /home/my/scripts/vps/vps_list.php sshable |grep -v 'Now using' >> servers.csv  ; \
tvps;
tsessrun 'cd /root/cpaneldirect && \
git pull --all && \
/bin/cp -fv /root/cpaneldirect/cli/provirted_completion /etc/bash_completion.d/ && \
if [ -e /etc/apt ]; then
  apt-get update && \
  apt-get autoremove -y --purge && \
  apt-get dist-upgrade -y && \
  apt-get autoremove -y --purge && \
  apt-get clean && \
  if [ $(php -v|head -n 1|cut -c5) -ge 7 ]; then
    exit;
  fi;
else
  yum update -y && \
  if [ $(php -v|head -n 1|cut -c5) -ge 7 ]; then
    exit;
  fi;
fi;'
```