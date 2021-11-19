<?php
namespace App;


/**
* Provides logging interface
*/
class Logger extends \CLIFramework\Logger
{
    public $logLevels = [
		'critical' => 1,
		'error' => 2,
		'warn' => 3,
		'info' => 4,
		'info2' => 5,
		'debug' => 6,
		'debug2' => 7,
    ];
    /** current level - any message level lower than this will be displayed. */
    public $level = 4;
    protected $indent = 0;
    protected $indentCharacter = '  ';
    protected $history = [];

    public function __construct(ServiceContainer $container = null) {
    }

    /**
    * adds to the history log
    *
    * @param array $data output string or array for command data
    */
    public function addHistory($data) {
    	if (count($this->history) > 0 && $this->history[count($this->history) - 1]['type'] == $data['type'] && in_array($data['type'], ['output', 'error']))
    		$this->history[count($this->history) - 1]['text'] .= $data['text'];
    	else
			$this->history[] = $data;
    }

    /**
    * returns the history data
    *
    * @return array the history data
    */
    public function getHistory() {
		return $this->history;
    }

    /**
     * error method write message to STDERR
     *
     * @param string $msg
     */
    public function error($msg) {
        $level = $this->logLevels['error'];
    	$this->addHistory(['type' => 'error', 'text' => $msg]);
        if ($level > $this->level)
            return;
        fprintf(STDERR, $msg.PHP_EOL);
    }

    public function __call($method, $args) {
        $msg = $args[0];
        $indent = isset($args[1]) ? $args[1] : 0;
        $level = $this->logLevels[$method];
        if ($level > $this->level) // do not print.
            return;
        if ($this->indent)
            $this->write(str_repeat($this->indentCharacter, $this->indent));
        $this->writeln(is_object($msg) || is_array($msg) ? print_r($msg, 1) : $msg); // detect object
    }

    /**
     * Write to console with given output format.
     *
     * $logger->writef('%s ... %s');
     *
     * @param string $format
     */
    public function writef($format) {
        $args = func_get_args();
        $this->write(call_user_func_array('sprintf', $args));
    }

    /**
     * @param string $text text to write by `writer`
     */
    public function write($text) {
    	if ($text != '') {
    		$this->addHistory(['type' => 'output', 'text' => $text]);
        	echo $text;
		}
    }

    /**
     * @param string $text write text and append a newline charactor.
     */
    public function writeln($text) {
        $this->write($text.PHP_EOL);
    }

    /**
     * Append a newline charactor to the console
     */
    public function newline() {
        $this->writeln('');
    }
}
