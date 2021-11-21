<?php
namespace App\Command\CronCommand;

use App\Vps;
use App\Os\Xinetd;
use CLIFramework\Command;
use CLIFramework\Formatter;
use CLIFramework\Logger\ActionLogger;
use CLIFramework\Debug\LineIndicator;
use CLIFramework\Debug\ConsoleDebug;

class VirtuozzoUpdateCommand extends Command {
	public function brief() {
		return "lists the history entries";
	}

    /** @param \GetOptionKit\OptionCollection $opts */
	public function options($opts) {
		parent::options($opts);
		$opts->add('v|verbose', 'increase output verbosity (stacked..use multiple times for even more output)')->isa('number')->incremental();
		$opts->add('t|virt:', 'Type of Virtualization, kvm, openvz, virtuozzo, lxc')->isa('string')->validValues(['kvm','openvz','virtuozzo','lxc']);
	}

    /** @param \CLIFramework\ArgInfoList $args */
	public function arguments($args) {
	}

	public function execute() {
		Vps::init($this->getOptions(), []);
/*
function age() {
   local filename=$1
   local changed=`stat -c %Y "$filename"`
   local now=`date +%s`
   local elapsed

   let elapsed=now-changed
   echo $elapsed
}

#yum upgrade -y;
/usr/bin/hostname && /usr/bin/hsotname -i
if [ "$(which vzpkg)" = "" ]; then
  echo "Cannot find vzpkg package for update_virtuozzo.sh script on $HOSTNAME" | mail support@interserver.net
else
  vzpkg update metadata;
  vzpkg list -O | awk '{ print $1 }' | xargs -n 1 vzpkg fetch -O;
  vzlist -a -H | awk '{ print $1 }' |xargs -n 1 vzpkg update;
  if [ ! -e ".cron_weekly.age" ] || [ $(age .cron_weekly.age) -ge 604800 ]; then
	vzpkg update cache --update-cache;
	touch .cron_weekly.age;
  fi
fi
*/
	}
}
