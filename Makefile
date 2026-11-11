# Minimum version of PHP supported by this project
PHP_VERSION ?= 8.3
PHP_EXTENSIONS ?= apcu mbstring json xdebug

DOCKER_IMAGE_NAME = mezzio/session-cache

# Includes _everything_ supplied by the internal tooling lib
include vendor/laminas/internal-tooling/Makefile


install-crc:
	cd tools/crc && composer install
.PHONY: install-crc

bump-crc:
	cd tools/crc && composer update && composer bump -D && composer update
.PHONY: bump-crc

composer-require-checker: ## Check composer.json for un-declared dependencies
	tools/crc/vendor/bin/composer-require-checker check \
		--config-file=tools/crc/config.json \
		composer.json
.PHONY: composer-require-checker
