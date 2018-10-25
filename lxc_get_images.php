<?php
$out = `lxc image list images:;`;
$valid_archs = array('x86_64','i686');
$json = json_decode(`lxc image list images: --format json`, true);
$images = [];
foreach ($json as $idx => $image) {
	if (in_array($image['architecture'], $valid_archs)) {
		$size = 0;
		if (!isset($image['aliases'])) {
			continue;
		}
		foreach ($image['aliases'] as $alias) {
			if ($size == 0 || strlen($alias['name']) < $size && preg_match('/^..*\/..*\/..*/', $alias['name'])) {
				$size = strlen($alias['name']);
				$name = $alias['name'];
			}
		}
		$images[] = array(
            'name' => $name,
            'description' => $image['properties']['description'],
            'os' => $image['properties']['os'],
            'release' => $image['properties']['release'],
            'architecture' => $image['architecture'],
        );
	}
}
echo json_encode($images).PHP_EOL;
