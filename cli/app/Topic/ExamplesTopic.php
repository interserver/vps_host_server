<?php
namespace App\Topic;

use CLIFramework\Topic\BaseTopic;

class ExamplesTopic extends BaseTopic {
	public $title = 'Example Command Lines';
	public $url = 'https://github.com/interserver/vps_host_server';

    public function getContent() {
    	return
'        Command Examples
            provirted create vps12345 162.246.19.201 ubuntu12 50 4096 4 p4ssw0rd;
            provirted create -i 162.246.19.202 -c 70.44.33.193 vps12345 162.246.19.201 ubuntu-20.04 100 4096 4 p4ssw0rd;
            provirted create --add-ip=208.73.201.161 --add-ip=208.73.201.162 --add-ip=208.73.201.163 \
                             --client-ip=70.44.33.193 vps12345 208.73.201.160 centos5 100 4096 4 p4ssw0rd;
            provirted stop vps12345;
            provirted start vps12345;
            provirted restart vps12345;
            provirted setup-vnc vps12345 70.44.33.193;
            provirted delete vps12345;
            provirted enable vps12345;
            provirted destroy vps12345;

        Contributing
            Got a great example and feel it should be included? Submit a pull request or issue.';
    }

	//public function getFooter() {}
}


