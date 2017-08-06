<?php
namespace MyAdmin\Daemon;

/**
 * Example API Consumer class implementing the PHP Simple Daemon Worker interface.
 * Simulates an API Call by generating random results and sleeping a randomly long amount of time.
 *
 * @author: Shane Harter
 */
class API implements \Core_IWorker
{
	/**
	 * Provided Automatically
	 * @var \Core_Worker_Mediator
	 */
	public $mediator;

	/**
	 * API Endpoint
	 * @var String
	 */
	private $uri;

	/**
	 * API Username
	 * @var String
	 */
	private $username;

	/**
	 * API Token
	 * @var String
	 */
	private $token;

	/**
	 * Array of results
	 * @var array
	 */
	private $results = [];

	/**
	 * Called on Construct or Init
	 * @return void
	 */
	public function setup() {
		// Read API details from the INI file
		// The ini plugin is created in the Poller::setup() method
		$ini = $this->mediator->daemon('ini');
		$this->uri      = $ini['api']['uri'];
		$this->username = $ini['api']['username'];
		$this->token    = $ini['api']['token'];
	}

	/**
	 * Called on Destruct
	 * @return void
	 */
	public function teardown() {

	}

	/**
	 * This is called during object construction 2to validate any dependencies
	 *
	 * @param array $errors
	 * @return array|\MyAdmin\Daemon\Array
	 *                  "Cannot open /tmp for Writing") or an empty array
	 */
	public function check_environment(Array $errors = []) {
		$errors = [];
		if (!function_exists('curl_init'))
			$errors[] = 'PHP Curl Extension Required: Recompile PHP using the --with-curl option.';

		// Currently this class just simulates an API call by generating random results and sleeping a random time.
		// Curl isn't actually being used but it's included here in the interest of making this feel more real and
		// therefore be a better example application.

		return $errors;
	}

	/**
	 * Poll the API for updated information -- Simulate an API call of varying duration.
	 *
	 * @param array $existing_results
	 * @return array|\MyAdmin\Daemon\Array
	 */
	public function poll(Array $existing_results) {
		static $calls = 0;
		$calls++;

		$this->results = $existing_results;
		$this->mediator->log('Calling API...');

		// If this is our first call, create initial results
	//      if ($calls == 1) {
			$this->results['vps'] = trim(`vzlist -a -j`);
			$this->results['sales'] = $this->results['customers'] * mt_rand(20, 100);
			return $this->results;
	//      }

		// Increase the stats in our results array accordingly
	//    $multiplier = mt_rand(100, 125) / 100;
	//    $this->results['customers'] = intval($this->results['customers'] * $multiplier);
	//    $this->results['sales'] = intval($this->results['sales'] * $multiplier);

	//    return $this->results;
	}

}
