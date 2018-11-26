#!/usr/bin/env php
<?php
/*
 VMWare MACs
  00:05:56
  00:50:56
  00:0C:29
  00:1C:14
 Xen MACs
  00:16:3E
*/
if ($_SERVER['argc'] == 1) {
    echo "Syntax: {$_SERVER['argv'][0]} <id> [module]
    id - the Service ID number
    module - vps or quickservers, defaults to vps, optional module this service is under";
    exit;
}
$id = $_SERVER['argv'][1];
$prefix = '00:16:3E';
if (isset($_SERVER['argv'][2])) {
    switch ($_SERVER['argv'][2]) {
        case 'qs':
        case 'quickservers':
            $prefix = '00:0C:29';
            break;
        case 'vps':
        default:
            $prefix = '00:16:3E';
            break;
    }
}
$suffix = strtoupper(sprintf("%06s", dechex($id)));
$mac = $prefix.':'.substr($suffix, 0, 2).':'.substr($suffix, 2, 2).':'.substr($suffix, 4, 2);
echo $mac.PHP_EOL;
