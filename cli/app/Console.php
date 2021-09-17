<?php
namespace App;

use CLIFramework\Application;

class Console extends Application
{
    const NAME = 'ProVirted';
    const VERSION = '2.0';

    public function init() {
    	$this->enableCommandAutoload();
        parent::init();
    	$this->topic('basic');
    	$this->commandGroup('Power Commands', ['stop', 'start', 'restart']);
    	$this->commandGroup('Provisioning', ['create', 'destroy', 'enable', 'delete', 'backup', 'restore']);
    	$this->commandGroup('Maintanance', ['block-smtp', 'change-hostname', 'change-timezone', 'setup-vnc', 'update-hdsize', 'reset-password', 'add-ip', 'remove-ip', 'enable-cd', 'disable-cd', 'eject-cd', 'insert-cd']);
    }
}
