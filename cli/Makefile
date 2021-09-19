all: completion phar

completion:
	php provirted.php bash --bind provirted --program provirted > provirted_completion
	chmod +x provirted_completion

phar:
	php provirted.php --debug archive --app-bootstrap --executable --no-compress provirted.phar
	chmod +x provirted.phar
