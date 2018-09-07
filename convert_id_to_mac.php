#!/usr/bin/env php
<?php
$id = $_SERVER['argv'][1];
$suffix = strtoupper(sprintf("%06s", dechex($id)));
$mac = '00:16:3E:'.substr($suffix, 0, 2).':'.substr($suffix, 2, 2).':'.substr($suffix, 4, 2);
echo $mac.PHP_EOL;
