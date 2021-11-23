<?php
namespace App;

use CLIFramework\Application;
use App\Vps;
use App\Logger;

class Console extends Application
{
    const NAME = 'ProVirted';
    const VERSION = '2.0';

    public function init() {
    	$this->enableCommandAutoload();
        parent::init();
    	$this->commandGroup('Power', ['stop', 'start', 'restart']);
    	$this->commandGroup('Provisioning', ['config', 'create', 'destroy', 'enable', 'delete', 'backup', 'restore', 'test']);
    	$this->commandGroup('Maintanance', ['install-cpanel', 'reset-password', 'update', 'cd', 'block-smtp', 'add-ip', 'remove-ip', 'change-ip', 'rebuild-dhcp', 'vnc']);
        $this->commandGroup("Development Commands", ['generate-internals'])->setId('dev');
    	$this->topic('basic');
    	$this->topic('examples');
    	//Vps::setLogger($this->getLogger());
    	Vps::setLogger(new Logger());
    	Vps::getLogger()->addHistory(['type' => 'program', 'text' => implode(' ', $_SERVER['argv']), 'start' => time()]);
    }

    public function finish() {
        parent::finish();
        $history = Vps::getLogger()->getHistory();
        if (count($history) > 1) {
	        $history[0]['end'] = time();
	        @mkdir($_SERVER['HOME'].'/.provirted', 0750, true);
			$allHistory = file_exists($_SERVER['HOME'].'/.provirted/history.json') ? json_decode(file_get_contents($_SERVER['HOME'].'/.provirted/history.json'), true) : [];
			$allHistory[] = $history;
	        file_put_contents($_SERVER['HOME'].'/.provirted/history.json', json_encode($allHistory, JSON_PRETTY_PRINT));
		}
    }
}
