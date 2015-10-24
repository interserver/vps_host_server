<?php
namespace MyAdmin\Daemon;

/**
 * A PHP Simple Daemon example application.
 * Use a background worker to continuously poll for updated information from an vzctl and bring that information into the
 * daemon process where it would be manipulated/used/updated/etc.
 *
 * @author Shane Harter
 */
class Poller extends \Core_Daemon
{
	/**
	* If you're building a server or using a blocking vzctl in your event loop like socket_listen or LibEvent, you should omit this.
	* How often (in seconds) should your execute() method be called. For example, you could have it run every second, or every 5 seconds, or every 60 seconds, etc. If your execute() method takes longer than $loop_interval to return, an error will be logged.
	*/
	protected $loop_interval = 3;

    /**
     * This will hold the results returned by our vzctl
     * @var array
     */
    protected $results = array();

    /**
     * Create a Lock File plugin to ensure we're not running duplicate processes, and load
     * the config file with all of our vzctl connection details
     */
	  protected function setup_plugins()
	  {
        $this->plugin('Lock_File');

		    $this->plugin('ini');
		    $this->ini->filename = BASE_PATH . '/config.ini';
		    //$this->ini->required_sections = array('api');
		    $this->ini->required_sections = array('vzctl');
		    $this->ini->required_sections = array('queue');
	  }

    protected function setup_workers()
    {
        $this->worker('Vzctl', new vzctl);
        $this->Vzctl->workers(1);

        $this->Vzctl->timeout(120);
        $this->Vzctl->onTimeout(function($call, $log) {
            $log("vzctl Timeout Reached");
        });

        $that = $this;
        $this->Vzctl->onReturn(function($call, $log) use($that) {
            if ($call->method == 'poll') {
                $that->set_results($call->return);
                $log("vzctl Results Updated...");
            }
        });

        $this->worker('Queue', new Queue);
        $this->Queue->workers(1);

        $this->Queue->timeout(120);
        $this->Queue->onTimeout(function($call, $log) {
            $log("Queue Timeout Reached");
        });

        $that = $this;
        $this->Queue->onReturn(function($call, $log) use($that) {
            if ($call->method == 'poll') {
                $that->set_results($call->return);
                $log("Queue Results Updated...");
            }
        });

    }

	/**
	 * The setup method is called only in your parent daemon class, after plugin_setup and worker_setup and before execute()
	 * @return void
	 * @throws Exception
	 */
	protected function setup()
	{
        // We don't need any additional setup.
        // Implement an empty method to satisfy the abstract base class
	}
	
	/**
	 * This daemon will perform a continuous long-poll request against an vzctl. When the vzctl returns, we'll update
     * our $results array, then start the next polling request. There will always be a background worker polling for
     * updated results.
	 * @return void
	 */
	protected function execute()
	{
	        if (!$this->Vzctl->is_idle()) {
        	    $this->log("Event Loop Iteration: vzctl Call running in the background worker process.");
	            return;
        	}
		else {
		        // If the Worker is idle, it means it just returned our stats.
		        // Log them and start another request
		        // If there isn't results yet, don't display incorrect (empty) values:
		        if (!empty($this->results['vps'])) {
        		    $this->log("Current VPS:   " . $this->results['vps']);
//		            $this->log("Current Sales Amount: $ " . number_format($this->results['sales'], 2));
		        }
		        // You can't store state in the worker processes because they can be killed, restarted, timed-out, etc.
		        // So even though we only have 1 worker process, we pass any state data in each call.
		        $this->Vzctl->poll($this->results);
		}
	        if (!$this->Queue->is_idle()) {
        	    $this->log("Event Loop Iteration: Queue Call running in the background worker process.");
	            return;
        	}
		else {
		        // If the Worker is idle, it means it just returned our stats.
		        // Log them and start another request
		        // If there isn't results yet, don't display incorrect (empty) values:
		        if (!empty($this->results['vps'])) {
        		    $this->log("Current Queue:   " . $this->results['queue']);
		        }
		        // You can't store state in the worker processes because they can be killed, restarted, timed-out, etc.
		        // So even though we only have 1 worker process, we pass any state data in each call.
		        $this->Queue->poll($this->results);
		}
	}

    public function set_results(Array $results) {
        $this->results = $results;
    }
	
	/**
	 * Dynamically build the file name for the log file. This simple algorithm 
	 * will rotate the logs once per day and try to keep them in a central /var/log location. 
	 * @return string
	 */
	protected function log_file()
	{	
		$dir = '/home/my/PHP-Daemon/Examples/MyAdmin/poller.log';
		if (@file_exists($dir) == false)
			@mkdir($dir, 0777, true);
		
		if (@is_writable($dir) == false)
			$dir = BASE_PATH . '/logs';
		
		return $dir . '/log_' . date('Ymd');
	}
}
