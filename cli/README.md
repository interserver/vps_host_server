# ProVirted

### About

### TODO

* _server-setup_ Installs PreRequisites, Configures Software for our setup
* _config_ - Management of the various settings
* _test_ - Perform various self diagnostics to check on the health and prepairedness of the system

### Command List

* _create_ Creates a Virtual Machine.
* _destroy_ Destroys a Virtual Machine.
* _enable_ Enables a Virtual Machine.
* _delete_ Deletes a Virtual Machine.
* _backup_ Creates a Backup of a Virtual Machine.
* _restore_ Restores a Virtual Machine from Backup.
* _stop_ Stops a Virtual Machine.
* _start_ Starts a Virtual Machine.
* _restart_ Restarts a Virtual Machine.
* _block-smtp_ Blocks SMTP on a Virtual Machine.
* _change-hostname_ ChangeHostnames a Virtual Machine.
* _change-timezone_ ChangeTimezones a Virtual Machine.
* _setup-vnc_ Setup VNC Allowed IP on a Virtual Machine.
* _update-hdsize_ Change the HD Size of a Virtual Machine.
* _reset-password_ Resets/Clears a Password on a Virtual Machine.
* _add-ip_ Adds an IP Address to a Virtual Machine.
* _remove-ip_ Removes an IP Address from a Virtual Machine.
* _enable-cd_ Enable the CD-ROM in a Virtual Machine.
* _disable-cd_ Disable the CD-ROM in a Virtual Machine.
* _eject-cd_ Eject a CD from a Virtual Machine.
* _insert-cd_ Insert a CD-ROM into a Virtual Machine.

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



