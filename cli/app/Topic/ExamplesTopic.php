<?php
namespace App\Topic;

use CLIFramework\Topic\BaseTopic;

class ExamplesTopic extends BaseTopic {
	public $title = 'Example Command Lines';
	public $url = 'https://github.com/interserver/vps_host_server';

    public function getContent() {
    	return
'        Command Examples
            provirted create vps100 162.246.19.201 ubuntu12 50 4096 4 p4ssw0rd
            provirted create --add-ip=162.246.19.202 --client-ip=70.44.33.193 vps100 162.246.19.201 ubuntu-20.04 100 4096 4 p4ssw0rd
            provirted create -i 208.73.201.161 -i 208.73.201.162 -i 208.73.201.163 -c 70.44.33.193 vps100 208.73.201.160 centos5 100 4096 4 p4ssw0rd
            provirted block-smtp vps100 100
            provirted block-smtp vps100
            provirted change-timezone vps100 America/New_York
            provirted stop vps100
            provirted start vps100
            provirted restart vps100
            provirted setup-vnc vps100 70.44.33.193
            provirted remove-ip vps100 162.246.19.202
            provirted add-ip vps100 162.246.19.202
            provirted delete vps100
            provirted enable vps100
            provirted destroy vps100
            provirted disable-cd vps100
            provirted enable-cd vps100 https://mirror.trouble-free.net/iso/KNOPPIX_V7.2.0CD-2013-06-16-EN.iso
            provirted eject-cd vps100
            provirted insert-cd vps100 https://mirror.trouble-free.net/iso/KNOPPIX_V7.2.0CD-2013-06-16-EN.iso
            provirted change-hostname vps100 vps101
            provirted update-hdsize vps100 150

            provirted reset-password vps100
            provirted backup vps101 101 detain@interserver.net
            provirted restore vps101 vps101-2021-09-18-13450.zst vps101 101


        Contributing
            Got a great example and feel it should be included? Submit a pull request or issue.';
    }

	//public function getFooter() {}
}


