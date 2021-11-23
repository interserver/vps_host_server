all: nodev phar completion

update:
	composer update --with-all-dependencies -v -o --ansi --dev

nodev:
	composer update --with-all-dependencies -v -o --ansi --no-dev

completion:
	rm -f provirted_completion
	php provirted.php bash --bind provirted.phar --program provirted.phar > provirted_completion
	chmod +x provirted_completion

phar:
	rm -f provirted.phar
	php provirted.php archive --composer=composer.json --app-bootstrap --executable --no-compress provirted.phar
	chmod +x provirted.phar

install:
	cp provirted_completion /etc/bash_completion.d/provirted
	ln -fs /root/cpaneldirect/cli/provirted.phar /usr/local/bin/provirted

internals:
	rm -rf app/Command/InternalsCommand
	php provirted.php generate-internals
