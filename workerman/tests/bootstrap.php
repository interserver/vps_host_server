<?php

/**
* PHPUnit bootstrap for the vps-host-service test suite (new in step 3.3).
*
* Loads composer autoload plus the in-memory test doubles used to drive the
* Agent / MessageDispatcher / Handlers without any real network, sockets, or a
* live Workerman event loop.
*/

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/Fakes/FakeConnection.php';
require __DIR__.'/Fakes/FakeGlobalData.php';
require __DIR__.'/phpunit/AgentTestCase.php';
