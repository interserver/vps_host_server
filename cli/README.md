# ProVirted

### About

### TODO

* _server-setup_ Installs PreRequisites, Configures Software for our setup
* _config_ - Management of the various settings
* _test_ - Perform various self diagnostics to check on the health and prepairedness of the system

### Commands

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
* **change-hostname** ChangeHostnames a Virtual Machine.
* **change-timezone** ChangeTimezones a Virtual Machine.
* **setup-vnc** Setup VNC Allowed IP on a Virtual Machine.
* **update-hdsize** Change the HD Size of a Virtual Machine.
* **reset-password** Resets/Clears a Password on a Virtual Machine.
* **add-ip** Adds an IP Address to a Virtual Machine.
* **remove-ip** Removes an IP Address from a Virtual Machine.
* **enable-cd** Enable the CD-ROM in a Virtual Machine.
* **disable-cd** Disable the CD-ROM in a Virtual Machine.
* **eject-cd** Eject a CD from a Virtual Machine.
* **insert-cd** Insert a CD-ROM into a Virtual Machine.

### Developer Links

* [CLIFramework GitHub repo](https://github.com/c9s/CLIFramework)
* [CLIFramework Wiki](https://github.com/c9s/CLIFramework/wiki)
* [Webman Docs](https://www.workerman.net/doc/webman)
* [Webman GitHub repo](https://github.com/walkor/webman)
* [PHP's best friend for the terminal.](https://github.com/thephpleague/climate)
* [CLImate Docs](https://climate.thephpleague.com/)
* [A simple cli framework](https://github.com/kylekatarnls/simple-cli)
* [PHP CLI application library, provide console argument parse, console controller/command run, color style, user interactive, format information show and more.](https://github.com/inhere/php-console)
* [php-console Wiki](https://github.com/inhere/php-console/wiki)
* [Colored CLI Table Output for PHP](https://github.com/jc21/clitable)
* [Build beautiful PHP CLI menus. Simple yet Powerful. Expressive DSL.](https://github.com/php-school/cli-menu)


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


