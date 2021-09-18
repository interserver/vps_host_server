# ProVirted

### About

Easy management of Virtualization technologies including KVM, OpenVZ and Virtuozzo.

### TODO

* Add the following Commands:
  * _server-setup_ Installs PreRequisites, Configures Software for our setup
  * _config_ - Management of the various settings
  * _test_ - Perform various self diagnostics to check on the health and prepairedness of the system

### Commands

| Command | Description |
| ------- | ----------- |
| create | Creates a Virtual Machine. |
| destroy | Destroys a Virtual Machine. |
| enable | Enables a Virtual Machine. |
| delete | Deletes a Virtual Machine. |
| backup | Creates a Backup of a Virtual Machine. |
| restore | Restores a Virtual Machine from Backup. |
| stop | Stops a Virtual Machine. |
| start | Starts a Virtual Machine. |
| restart | Restarts a Virtual Machine. |
| block-smtp | Blocks SMTP on a Virtual Machine. |
| change-hostname | ChangeHostnames a Virtual Machine. |
| change-timezone | ChangeTimezones a Virtual Machine. |
| setup-vnc | Setup VNC Allowed IP on a Virtual Machine. |
| update-hdsize | Change the HD Size of a Virtual Machine. |
| reset-password | Resets/Clears a Password on a Virtual Machine. |
| add-ip | Adds an IP Address to a Virtual Machine. |
| remove-ip | Removes an IP Address from a Virtual Machine. |
| enable-cd | Enable the CD-ROM in a Virtual Machine. |
| disable-cd | Disable the CD-ROM in a Virtual Machine. |
| eject-cd | Eject a CD from a Virtual Machine. |
| insert-cd | Insert a CD-ROM into a Virtual Machine. |

### Developer Links

| Link | Description |
| ---- | ----------- |
| [c9s/CLIFramework](https://github.com/c9s/CLIFramework) | CLIFramework GitHub repo |
| [c9s/CLIFramework/wiki](https://github.com/c9s/CLIFramework/wiki) | CLIFramework Wiki |
| [walkor/webman](https://github.com/walkor/webman) | Webman GitHub repo |
| [workerman.net/doc/webman](https://www.workerman.net/doc/webman) | Webman Docs |
| [thephpleague/climate](https://github.com/thephpleague/climate) | PHP's best friend for the terminal. |
| [climate.thephpleague.com/](https://climate.thephpleague.com/) | CLImate Docs |
| [kylekatarnls/simple-cli](https://github.com/kylekatarnls/simple-cli) | A simple cli framework |
| [inhere/php-console](https://github.com/inhere/php-console) | PHP CLI application library, provide console argument parse, console controller/command run, color style, user interactive, format information show and more. |
| [inhere/php-console/wiki](https://github.com/inhere/php-console/wiki) | php-console Wiki |
| [jc21/clitable](https://github.com/jc21/clitable) | Colored CLI Table Output for PHP |
| [php-school/cli-menu](https://github.com/php-school/cli-menu) | Build beautiful PHP CLI menus. Simple yet Powerful. Expressive DSL. |


### Building

#### Setup Bash Completion

```bash
php provirted.php bash --bind provirted --program provirted > /etc/bash_completion.d/provirted
```

#### Compile the code into a PHAR

Not sure yet if I want to just go with the php file and source tree or a single phar file.  A phar would probablybe simpler.

I can generate a PHAR with the following command:
```bash
php provirted.php --debug archive --app-bootstrap --executable --no-compress provirted.phar
```

#### Terminal Recording

##### asciinema

* [https://github.com/asciinema/asciinema](https://github.com/asciinema/asciinema)
* [https://asciinema.org/docs/how-it-works](https://asciinema.org/docs/how-it-works)

```bash
apt-get install asciinema
asciinema rec mydemo.cast
asciinema play mydemo.cast
asciinema upload mydemo.cast
```

##### terminalizer

* [https://github.com/faressoft/terminalizer](https://github.com/faressoft/terminalizer)
* [https://terminalizer.com/](https://terminalizer.com/)
* [https://terminalizer.com/docs](https://terminalizer.com/docs)

```bash
npm install -g terminalizer
terminalizer record mydemo
terminalizer render mydemo
terminalizer play mydemo
```

##### termtosvg

* [https://github.com/nbedos/termtosvg](https://github.com/nbedos/termtosvg)
* [https://github.com/nbedos/termtosvg/blob/develop/man/termtosvg.md](https://github.com/nbedos/termtosvg/blob/develop/man/termtosvg.md)
* [https://nbedos.github.io/termtosvg/](https://nbedos.github.io/termtosvg/)

```bash
pip3 install termtosvg
termtosvg mydemo.svcg
termtosvg record mydemo.svg
termtosvg render mydemo.cast mydemo.svg
```

### Testing

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
