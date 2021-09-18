<?php
namespace App\Topic;

use CLIFramework\Topic\BaseTopic;

class ExamplesTopic extends BaseTopic {
	public $title = 'Example Command Lines';
	public $url = 'https://github.com/interserver/vps_host_server';

    public function getContent() {
    	return
'        system setup
            system setup info here

        vps install
            info about the vps installs

        templates
            templates are stored in /vz/templates or / usually

        storage
            info here about storage';
    }

	//public function getFooter() {}
}


