COMPOSE ?= docker compose
# Pages per site in a local full crawl; 0 crawls everything.
LIMIT ?= 50
# Arguments for `make composer` and `make console`, e.g. ARGS="require vendor/package".
ARGS ?=
# The base images' tag for `make build-openshift`.
DOCKER_BUILD_TAG ?= latest

.DEFAULT_GOAL := help

PHONY :=

PHONY += help
help: ## Show the available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

# Local development

.env:
	printf 'WEBHOOK_TOKEN=%s\n' "$$(openssl rand -hex 24)" > .env

# LOCAL_UID: the services run as your user, so the files they create in the
# project folder (vendor/, var/) belong to you. compose.yaml reads it from .env.
PHONY += env
env: .env ## Create .env, or add missing settings to it
	@grep -q '^LOCAL_UID=' .env || printf 'LOCAL_UID=%s\n' "$$(id -u)" >> .env

PHONY += setup
setup: env build install up ## First-time setup: .env, images, dependencies, start

PHONY += build
build: ## Build the local images
	$(COMPOSE) build --pull

PHONY += install
install: ## Install the PHP dependencies
	$(COMPOSE) run --rm --no-deps --entrypoint composer webhook install

PHONY += up
up: ## Start everything (runs a limited full crawl)
	$(COMPOSE) up -d

PHONY += down
down: ## Stop and remove the containers (keeps the index)
	$(COMPOSE) down

PHONY += restart-worker
restart-worker: ## Restart the looping worker, e.g. after code changes
	$(COMPOSE) restart worker

PHONY += crawl
crawl: ## Full crawl, LIMIT pages per site (default 50)
	CRAWL_LIMIT_PER_DOMAIN=$(LIMIT) $(COMPOSE) run --rm crawler

PHONY += crawl-all
crawl-all: ## Full crawl of every page
	$(MAKE) crawl LIMIT=0

PHONY += process-queue
process-queue: ## Process the queue now instead of waiting for the worker
	$(COMPOSE) stop worker
	$(COMPOSE) run --rm worker php /app/bin/console app:worker; status=$$?; $(COMPOSE) start worker; exit $$status

PHONY += search
search: ## Show the indexed documents
	$(COMPOSE) exec crawler-elastic curl -s "localhost:9200/hel-fi/_search?pretty"

PHONY += console
console: ## Run a Symfony console command, e.g. make console ARGS="debug:router"
	$(COMPOSE) run --rm --no-deps worker php /app/bin/console $(ARGS)

PHONY += composer
composer: ## Run Composer, e.g. make composer ARGS="require vendor/package"
	$(COMPOSE) exec webhook composer $(ARGS)

# Tests and code quality

PHONY += test
test: ## Unit tests
	$(COMPOSE) exec webhook vendor/bin/phpunit

PHONY += coverage
coverage: ## Unit tests with coverage (PCOV)
	$(COMPOSE) exec webhook php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text

PHONY += test-integration
test-integration: ## Integration tests: the real crawler and Elasticsearch
	$(COMPOSE) run --rm worker vendor/bin/phpunit --testsuite integration

PHONY += phpcs
phpcs: ## Check the coding standard (PSR-12)
	$(COMPOSE) exec webhook vendor/bin/phpcs

PHONY += phpcbf
phpcbf: ## Fix coding standard violations
	$(COMPOSE) exec webhook vendor/bin/phpcbf

PHONY += phpstan
phpstan: ## Static analysis (PHPStan, level max)
	$(COMPOSE) exec webhook vendor/bin/phpstan analyse

PHONY += check
check: phpcs phpstan test test-integration ## All checks: PHPCS, PHPStan, unit and integration tests

# OpenShift

PHONY += build-openshift
build-openshift: ## Build the OpenShift images (DOCKER_BUILD_TAG=dev for the dev base images)
	docker build -f docker/openshift/webhook/Dockerfile --build-arg DOCKER_BUILD_TAG=$(DOCKER_BUILD_TAG) -t helfi-search-webhook:openshift .
	docker build -f docker/openshift/worker/Dockerfile --build-arg DOCKER_BUILD_TAG=$(DOCKER_BUILD_TAG) -t helfi-search-worker:openshift .

.PHONY: $(PHONY)
