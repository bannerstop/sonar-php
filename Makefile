# `make ci` runs everything the CI matrix runs, in throwaway containers as the current user.
UID_GID := $(shell id -u):$(shell id -g)
DOCKER_PHP = docker run --rm --user $(UID_GID) -v $(CURDIR):/app -w /app
COMPOSER = docker run --rm --user $(UID_GID) -e COMPOSER_HOME=/tmp/composer -v $(CURDIR):/app -w /app composer:2

.PHONY: ci test test-72 test-85 phpstan cs cs-fix deps-72 deps-85 tools

ci: test phpstan cs

test: test-72 test-85

test-72: .vendor/72/autoload.php
	$(DOCKER_PHP) php:7.2-cli .vendor/72/bin/phpunit --bootstrap .vendor/72/autoload.php

test-85: .vendor/85/autoload.php
	$(DOCKER_PHP) php:8.5-cli .vendor/85/bin/phpunit --bootstrap .vendor/85/autoload.php

.vendor/72/autoload.php: composer.json tools/install-deps.sh
	$(COMPOSER) tools/install-deps.sh 72 7.2.5

.vendor/85/autoload.php: composer.json tools/install-deps.sh
	$(COMPOSER) tools/install-deps.sh 85 8.5.0

tools/phpstan/vendor/autoload.php: tools/phpstan/composer.json
	$(COMPOSER) composer update --no-interaction --no-progress -d tools/phpstan

tools/php-cs-fixer/vendor/autoload.php: tools/php-cs-fixer/composer.json
	$(COMPOSER) composer update --no-interaction --no-progress -d tools/php-cs-fixer

phpstan: tools/phpstan/vendor/autoload.php .vendor/85/autoload.php
	$(DOCKER_PHP) php:8.5-cli tools/phpstan/vendor/bin/phpstan analyse --memory-limit=512M

cs: tools/php-cs-fixer/vendor/autoload.php
	$(DOCKER_PHP) php:8.5-cli tools/php-cs-fixer/vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix: tools/php-cs-fixer/vendor/autoload.php
	$(DOCKER_PHP) php:8.5-cli tools/php-cs-fixer/vendor/bin/php-cs-fixer fix
