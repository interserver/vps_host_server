<?php
return function($stdObject, $cmd) {
	$output = trim(`$cmd`);
	return $output;
};
