BASE_COMMAND=docker compose -p $(shell basename $(CURDIR))

build:
	$(BASE_COMMAND) build --pull

test: build
	$(BASE_COMMAND) run test
	$(BASE_COMMAND) down

logs:
	$(BASE_COMMAND) logs

up:
	$(BASE_COMMAND) up -d emulator

# Run phpunit directly against the running emulator, skipping build/composer-install/phpstan.
# Usage: make phpunit ARGS="--filter=testBeginTransaction tests/ConnectionTest.php"
phpunit: up
	$(BASE_COMMAND) run --rm test vendor/bin/phpunit $(ARGS)

update:
	$(BASE_COMMAND) run test composer update
	$(BASE_COMMAND) down

bash:
	$(BASE_COMMAND) run test /bin/sh

down:
	$(BASE_COMMAND) down --remove-orphans
