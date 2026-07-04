.DEFAULT_GOAL := help
DOCKER?=docker
DOCKER_COMPOSE?=docker compose
MUTATION_MEMORY_LIMIT?=1G
MUTATION_THREADS?=4
UID:=$(shell id -u)
GID:=$(shell id -g)
EXEC_PHP = $(DOCKER_COMPOSE) exec php
EXEC_DB = $(DOCKER_COMPOSE) exec database
EXEC_PHP_CI = $(DOCKER_COMPOSE) exec -T php
SYMFONY  = $(EXEC_PHP) bin/console

## —— Project setup 🚀 ———————————————————————————————————————————————————————
.PHONY: install start stop restart

install:  ## Install and start the project with vendors update
	@$(MAKE) stop
	@echo "build project"
	$(DOCKER_COMPOSE) pull --ignore-pull-failures
	$(DOCKER_COMPOSE) build --force-rm --pull
	@echo "install project"
	@docker compose run --rm php composer install --no-scripts
	@$(MAKE) start
	@$(EXEC_PHP) php bin/console assets:install
	@$(EXEC_PHP) php bin/console cache:clear
	@echo "drop all db if exist and create it"
	@$(MAKE) db-drop
	@echo "Creating database for dev area"
	@$(MAKE) db-create
	@echo "load migration"
	@$(EXEC_PHP) bin/console doctrine:migrations:migrate -n

start: ## Launch the project
	# Start service and wait for the entrypoint's own install/migrations to finish
	@$(DOCKER_COMPOSE) up -d --remove-orphans --wait
	# Install dependencies with right user (no-op if already up to date)
	@$(EXEC_PHP) composer install

stop: ## Stop the project
	@$(DOCKER_COMPOSE) down --remove-orphans

restart: ## Restart the project
	@$(MAKE) stop
	@$(MAKE) start

## —— Code quality 📋 ————————————————————————————————————————————————————————
.PHONY: test test-coverage

test: ## Run PHPUnit tests
	@echo "🧪 Exécution des tests avec PHPUnit..."
	@test -f phpunit.xml || cp phpunit.dist.xml phpunit.xml
	@$(EXEC_PHP) vendor/bin/phpunit --configuration phpunit.xml --no-coverage --display-notices --display-phpunit-deprecations

test-coverage: ## Run PHPUnit tests with code coverage
	@if test -f phpunit.xml; then \
      echo "🧪 Couverture de test avec un fichier de configuration local.\n" && \
	  $(DOCKER_COMPOSE) exec -e XDEBUG_MODE=coverage php vendor/bin/phpunit --configuration phpunit.xml --coverage-html ./coverage; \
	else \
	  echo "🧪 Couverture de test avec le fichier de configuration par défaut.\n" && \
	  $(DOCKER_COMPOSE) exec -e XDEBUG_MODE=coverage php vendor/bin/phpunit --configuration phpunit.dist.xml --coverage-html ./coverage; \
	fi


## —— Helper 🛠️ ——————————————————————————————————————————————————————————————
.PHONY: sh sf help remove-obsolete-branch

sh: ## Open a shell in the PHP container
	$(EXEC_PHP) bash

sf: ## List all Symfony commands or pass the parameter "c=" to run a given command, example: make sf c=about
	@$(eval c ?=)
	@$(SYMFONY) $(c)

remove-obsolete-branch: ## Remove local branches that have been deleted from the remote repository
	@echo "🔍 Fetching and pruning remote branches..."
		@git fetch --prune
		@branches=$$(git branch -vv | grep ': gone]' | awk '{print $$1}'); \
		if [ -z "$$branches" ]; then \
			echo "✨ No obsolete branches to remove"; \
		else \
			echo "🗑️  Removing obsolete branches:"; \
			echo "$$branches"; \
			echo "$$branches" | xargs git branch -D; \
			echo "✅ Cleanup completed!"; \
		fi

help: ## Outputs this help screen
	@grep -E '(^[a-zA-Z0-9_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' | sed -e 's/\[32m##/[33m/'


## —— Database 🗄️ ————————————————————————————————————————————————————————————
.PHONY: db-drop db-create db-reset-test schema-validate migrate create-migration rollback rollup

db-drop: ## Drop database
	@$(EXEC_PHP) bin/console doctrine:database:drop --force --if-exists -nq

db-create: ## Create/Recreate the database
	@$(EXEC_PHP) bin/console doctrine:database:create -nq

db-reset-test:
	@$(EXEC_PHP) bin/console doctrine:database:drop --env test --force --if-exists -nq
	@$(EXEC_PHP) bin/console doctrine:database:create --env test -nq
	@$(EXEC_PHP) bin/console doctrine:migrations:migrate --env test -n

schema-validate: ## Validate database schema
	@$(EXEC_PHP) bin/console doctrine:schema:validate -vv

migrate: ## Run Doctrine migrations
	@$(EXEC_PHP) bin/console doctrine:migrations:migrate -n

rollback: ## Down last migration
	@$(EXEC_PHP) bin/console doctrine:migrations:migrate prev -n

rollup: ## Migrate next migration
	@$(EXEC_PHP) bin/console doctrine:migrations:migrate next -n

create-migration: ## Create a new Doctrine migration
	@$(EXEC_PHP) bin/console make:migration


## —— Fixtures 🗃️ ————————————————————————————————————————————————————————————
.PHONY: fixtures-load-test fixtures-load-dev fixtures-load-append-dev fixtures-load-append-test

fixtures-load-test: ## Load fixtures into the test database
	@$(EXEC_PHP) bin/console doctrine:fixtures:load --env=test -n

fixtures-load-dev: ## Load fixtures into the development database
	@$(EXEC_PHP) bin/console doctrine:fixtures:load --env=dev -n

fixtures-load-append-dev: ## Append the fixtures instead of flushing the database
	@$(EXEC_PHP) bin/console doctrine:fixtures:load --env=dev --append -n

fixtures-load-append-test: ## Append the fixtures instead of flushing the database
	@$(EXEC_PHP) bin/console doctrine:fixtures:load --env=test --append -n

