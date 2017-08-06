<?php
echo "Building LXC Image List\n";
$out = `lxc image list images:;`;
preg_match_all('/^\|\s*(?P<alias>\S+)\s.*\|\s*(?P<fingerprint>[a-z0-9]*)\s*\|\s*(?P<public>\S+)\s*\|\s*(?P<description>.*)\s*\|\s*(?P<arch>i686|x86_64)\s*\|\s*(?P<size>\S+)\s*\|\s*(?P<upload_date>.*)\s*\|$/mU', $out, $matches);
foreach ($matches[0] as $idx => $match)
	$images[$idx] = array(
		'alias' => $matches['alias'][$idx],
		'fingerprint' => $matches['fingerprint'][$idx],
		'public' => $matches['public'][$idx],
		'description' => $matches['description'][$idx],
		'arch' => $matches['arch'][$idx],
		'size' => $matches['size'][$idx],
		'upload_date' => $matches['upload_date'][$idx]
	);
echo json_encode($images, JSON_PRETTY_PRINT) . "\n";
//print_r($images);
