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
    	$this->commandGroup('Maintanance', ['change-hostname', 'change-timezone', 'install-cpanel', 'reset-password', 'update',
        	'block-smtp', 'add-ip', 'remove-ip', 'change-ip', 'setup-vnc',
        	'enable-cd', 'disable-cd', 'eject-cd', 'insert-cd']);
        $this->commandGroup("Development", [
        	'source',
        ]);
    	$this->topic('basic');
    	$this->topic('examples');
        Vps::setLogger($this->getLogger());
    }
}
