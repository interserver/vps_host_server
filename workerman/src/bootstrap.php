<?php
global $composer, $settings;

$composer = include __DIR__.'/vendor/autoload.php';
$settings = include __DIR__.'/src/Config/settings.php';
include_once __DIR__.'/src/functions.php';
