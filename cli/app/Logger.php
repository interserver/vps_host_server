<?php
namespace App;


/**
* Provides logging interface
*/
class Logger
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

    public function __construct() {
    }

    public function setLevel($level, $indent = 0) {
        $this->level = $level;
    }

    public function getLevel() {
        return $this->level;
    }

    public function indent($level = 1) {
        $this->indent += $level;
    }

    public function unIndent($level = 1) {
        $this->indent = max(0, $this->indent - $level);
    }

    public function resetIndent() {
        $this->indent = 0;
    }

    /**
     * error method write message to STDERR
     *
     * @param string $msg
     */
    public function error($msg) {
        $level = $this->getLevelByName('error');
        if ($level > $this->level)
            return;
        fprintf(STDERR, $msg.PHP_EOL);
    }

    public function __call($method, $args) {
        $msg = $args[0];
        $indent = isset($args[1]) ? $args[1] : 0;
        $level = $this->getLevelByName($method);
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
        $this->write($text);
    }

    /**
     * @param string $text write text and append a newline charactor.
     */
    public function writeln($text) {
        $this->writeln($text);
    }

    /**
     * Append a newline charactor to the console
     */
    public function newline() {
        $this->writeln('');
    }
}
