# ProVirted

## CLI Commands Plan

Needs to be able to configure the systems, set them up , start/restart services,

* config - Management of the various settings
* test - Perform various self diagnostics to check on the health and prepairedness of the system
* API - The following commands have been implemnted from the API into the CLi
  * *create*  Creates a Virtual Machine.
  * *destroy*  Destroys a Virtual Machine.
  * *enable*  Enables a Virtual Machine.
  * *delete*  Deletes a Virtual Machine.
  * *backup*  Creates a Backup of a Virtual Machine.
  * *restore*  Restores a Virtual Machine from Backup.
  * *stop*  Stops a Virtual Machine.
  * *start*  Starts a Virtual Machine.
  * *restart*  Restarts a Virtual Machine.
  * *block-smtp*  Blocks SMTP on a Virtual Machine.
  * *change-hostname*  ChangeHostnames a Virtual Machine.
  * *change-timezone*  ChangeTimezones a Virtual Machine.
  * *setup-vnc*  Setup VNC Allowed IP on a Virtual Machine.
  * *update-hdsize*  Change the HD Size of a Virtual Machine.
  * *reset-password*  Resets/Clears a Password on a Virtual Machine.
  * *add-ip*  Adds an IP Address to a Virtual Machine.
  * *remove-ip*  Removes an IP Address from a Virtual Machine.
  * *enable-cd*  EnableCds a Virtual Machine.
  * *disable-cd*  DisableCds a Virtual Machine.
  * *eject-cd*  Eject a CD from a Virtual Machine.
  * *insert-cd*  Insert a CD-ROM into a Virtual Machine.

## Building

Not sure yet if I want to just go with the php file and source tree or a single phar file.  A phar would probablybe simpler.

I can generate a PHAR with the following command:
```bash
php provirted.php --debug archive --app-bootstrap --executable --no-compress provirted.phar
```

## Developer Links

* [https://github.com/c9s/CLIFramework](CLIFramework GitHub repo)
* [https://github.com/c9s/CLIFramework/wiki](CLIFramework Wiki)
* [https://www.workerman.net/doc/webman](Webman Docs)
* [https://github.com/walkor/webman](Webman GitHub repo)


