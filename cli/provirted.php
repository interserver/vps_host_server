#!/usr/bin/env php
<?php
require 'vendor/autoload.php'; // Only if autoload is not set up already.
$app = new \App\Console;
$app->runWithTry($argv); // $argv is a global variable containing command line arguments.
