<?php
return function ($stdObject) {
	return array(
		'ssl' => array( // use the absolute/full paths
			'local_cert' => __DIR__.'/myadmin.crt',
			'local_pk' => __DIR__.'/myadmin.key',
			'verify_peer' => false,
			'verify_peer_name' => false,
		)
	);
};
