<?php
return function validIp($stdObject, $ip, $display_errors = true, $support_ipv6 = false) {
	if (version_compare(PHP_VERSION, '5.2.0') >= 0) {
		if(filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)
			if ($support_ipv6 === false || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false)
				return false;
	} else {
		if (!preg_match("/^[0-9\.]{7,15}$/", $ip)) {
			// don't display errors cuz this gets called w/ a blank entry when people didn't even submit anything yet
			//add_output('<font class="error">IP '.$ip.' Too short/long</font>');
			return false;
		}
		$quads = explode('.', $ip);
		$numquads = count($quads);
		if ($numquads != 4) {
			if ($display_errors)
				error_log('<font class="error">IP '.$ip.' Too many quads</font>');
			return false;
		}
		for ($i = 0; $i < 4; $i++)
			if ($quads[$i] > 255) {
				if ($display_errors)
					error_log('<font class="error">IP '.$ip.' number '.$quads[$i].' too high</font>');
				return false;
			}
	}
	return true;
};
