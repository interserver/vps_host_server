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
    }
}
