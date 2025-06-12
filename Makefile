BASE_COMMAND=docker compose -p $(shell basename $(CURDIR))

build:
	$(BASE_COMMAND) build --pull

test: build
	$(BASE_COMMAND) run test
	$(BASE_COMMAND) down

logs:
	$(BASE_COMMAND) logs

update:
	$(BASE_COMMAND) run test composer update
	$(BASE_COMMAND) down

bash:
	$(BASE_COMMAND) run test /bin/sh

down:
	$(BASE_COMMAND) down --remove-orphans

ci-fix:
	$(BASE_COMMAND) run --no-deps --rm test composer fix -- --dry-run --diff
