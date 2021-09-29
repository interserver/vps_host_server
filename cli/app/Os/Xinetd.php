<?php
namespace App\Os;

use App\Vps;

/**
* Xinetd Service Management Class
*/
class Xinetd
{

	public static function lock() {
		touch('/tmp/_securexinetd');
	}

	public static function unlock() {
		echo Vps::runCommand("rm -f /tmp/_securexinetd;");
	}

	public static function remove($hostname) {
		$hostname = escapeshellarg($hostname);
		echo Vps::runCommand("rm -f /etc/xinetd.d/{$hostname} /etc/xinetd.d/{$hostname}-spice");
	}

	public static function restart() {
		echo Vps::runCommand("service xinetd restart 2>/dev/null || /etc/init.d/xinetd restart 2>/dev/null");
	}

    /**
    * is the service running
    * @return bool
    */
	public static function isRunning() {
		echo Vps::runCommand('pidof xinetd >/dev/null', $return);
		return $return == 0;
	}
}
