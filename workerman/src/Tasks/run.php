<?php
return function($stdObject, $cmd) {
	if (is_array($cmd)) {
		$command = $cmd['cmd'];
		$output = trim(`$command`);
	} else
		$output = trim(`$cmd`);
	return $output;
};
