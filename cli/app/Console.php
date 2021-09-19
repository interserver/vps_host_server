<?php
namespace App;

use CLIFramework\Application;
use App\Vps;

class Console extends Application
{
    const NAME = 'ProVirted';
    const VERSION = '2.0';

    public function init() {
    	$this->enableCommandAutoload();
        parent::init();
    	$this->commandGroup('Power', ['stop', 'start', 'restart']);
    	$this->commandGroup('Provisioning', ['create', 'destroy', 'enable', 'delete', 'backup', 'restore', 'test']);
    	$this->commandGroup('Maintanance', ['block-smtp', 'change-hostname', 'change-timezone', 'setup-vnc', 'update-hdsize', 'reset-password',
    	'add-ip', 'remove-ip', 'enable-cd', 'disable-cd', 'eject-cd', 'insert-cd']);
    	$this->topic('basic');
    	$this->topic('examples');
        Vps::setLogger($this->getLogger());
    }
}
