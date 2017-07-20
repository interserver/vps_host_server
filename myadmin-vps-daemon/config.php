<?php

/**
 *
 * Note: Nothing in this config file is specifically required to run a PHP Simple Daemon application. You can integrate it
 * into an existing bootstrap if you want.
 *
 * Note: When using external tools (like crontab or process managers like supervisord) the working directory may be different
 * than what you expect: Using relative paths and "./" based paths may not work as expected. You should always use absolute
 * paths when accessing filesystem resources. Setting a constant like we do with BASE_PATH below can be helpful.
 *
 */

date_default_timezone_set('America/New_York');

// The custom error handlers that ship with PHP Simple Daemon respect all PHP INI error settings.
ini_set('error_log', '/home/my/PHP-Daemon/Examples/MyAdmin/phpcli.log');
ini_set('display_errors', 1);

// Define a simple Auto Loader:
// Add the current application and the PHP Simple Daemon ./Core library to the existing include path
// Then set an __autoload function that uses Zend Framework naming conventions.
define('BASE_PATH', __DIR__);
set_include_path(implode(PATH_SEPARATOR, [
	realpath(BASE_PATH),
	realpath(BASE_PATH.'/src/MyAdmin/Daemon'),
	realpath(BASE_PATH.'/vendor/shaneharter/php-daemon'),
	realpath(BASE_PATH.'/vendor/shaneharter/php-daemon/Core'),
	get_include_path()
                                       ]
                 ));
/**
 * @param $class_name
 */
function __autoload($class_name) {
	$class_name = str_replace('\\', '/', $class_name);
	$class_name = str_replace('_', '/', $class_name);
	require_once "$class_name.php";
}

/**
 * @param $class_name
 * @return string
 */
function pathify($class_name) {
	return str_replace('_', '/', $class_name) . '.php';
}
