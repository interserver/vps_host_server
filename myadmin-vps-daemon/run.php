#!/usr/bin/env php
<?php
require_once 'config.php';
require_once 'error_handlers.php';

use MyAdmin\Daemon;

// The run() method will start the daemon event loop.
MyAdmin\Daemon\Poller::getInstance()->run();
