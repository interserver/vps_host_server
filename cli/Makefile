all: completion phar

completion:
	rm -f provirted_completion
	php provirted.php bash --bind provirted.phar --program provirted.phar > provirted_completion
	chmod +x provirted_completion

phar:
	rm -f provirted.phar
	php provirted.php archive --composer=composer.json --app-bootstrap --executable --compress=gz provirted.phar
	chmod +x provirted.phar

install:
	cp provirted_completion /etc/bash_completion.d/provirted
	ln -fs /root/cpaneldirect/cli/provirted.phar /usr/local/bin/provirted

internals:
	php provirted.php generate-internals
