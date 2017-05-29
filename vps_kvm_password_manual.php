<?php

	function shadow($password) {
		$hash = '';
		for($i=0;$i<8;$i++)
		{
			$j = mt_rand(0, 53);
			if($j<26)$hash .= chr(rand(65, 90));
			elseif($j<52)$hash .= chr(rand(97, 122));
			elseif($j<53)$hash .= '.';
			else $hash .= '/';
		}
		return crypt($password, '$1$'.$hash.'$');
	}

	$pass = $_SERVER['argv'][1];
	$root = $_SERVER['argv'][2];
	if (trim($root) == '')
	{
		echo "No root path specified\n";
		exit;
	}
	if (!file_exists($root . '/etc/shadow'))
	{
		echo "Cannot find etc/shadow file in $root\n";
		exit;
	}
	$shadow = file_get_contents($root . '/etc/shadow');
	$lines = explode("\n", $shadow);
	$found = false;
	foreach ($lines as $idx => $line)
	{
		$parts = explode(':', $line);
		if ($parts[0] == 'root')
		{
			$found = true;
			$parts[1] = shadow($pass);
			$lines[$idx] = implode(':', $parts);
		}
	}
	if ($found !== true)
	{
		echo "Couldnt find user root\n";
		exit;
	}
	$shadow = implode("\n", $lines);
	file_put_contents($root . '/etc/shadow', $shadow);
	echo "Password File Updated Using PHP crypt()\n";
