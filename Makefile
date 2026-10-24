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
	@$(MAKE) jwt-keys
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
.PHONY: test test-coverage deptrac phpstan api-doc-validate api-doc-coverage openapi openapi-check jwt-keys quality sql-budget-update drive-samples deploy-smoke

test: ## Run PHPUnit tests
	@echo "🧪 Exécution des tests avec PHPUnit..."
	@$(EXEC_PHP) vendor/bin/phpunit --no-coverage --display-notices --display-phpunit-notices --display-phpunit-deprecations

test-coverage: ## Run PHPUnit tests with code coverage
	@if test -f phpunit.xml; then \
      echo "🧪 Couverture de test avec un fichier de configuration local.\n" && \
	  $(DOCKER_COMPOSE) exec -e XDEBUG_MODE=coverage php vendor/bin/phpunit --configuration phpunit.xml --coverage-html ./coverage; \
	else \
	  echo "🧪 Couverture de test avec le fichier de configuration par défaut.\n" && \
	  $(DOCKER_COMPOSE) exec -e XDEBUG_MODE=coverage php vendor/bin/phpunit --configuration phpunit.dist.xml --coverage-html ./coverage; \
	fi

deptrac: ## Run Deptrac to check architectural dependencies
	@echo "Check des dépendances entre Domain, Application et Infrastructure"
	@$(EXEC_PHP) vendor/bin/deptrac analyse --config-file=quality-rules/Deptrac/deptrac.layer.yaml --no-progress
	@echo "Check des dépendances entre bounded contexts"
	@$(EXEC_PHP) vendor/bin/deptrac analyse --config-file=quality-rules/Deptrac/deptrac.bounded-context.yaml --no-progress
	@echo "Check des dépendances : un Repository ne peut pas être appelé dans une Query"
	@$(EXEC_PHP) vendor/bin/deptrac analyse --config-file=quality-rules/Deptrac/deptrac.no-repo-in-queryhandler.yaml --no-progress
	@echo "Check des dépendances : un Loader ne peut pas être appelé dans une Command"
	@$(EXEC_PHP) vendor/bin/deptrac analyse --config-file=quality-rules/Deptrac/deptrac.no-loader-in-commandhandler.yaml --no-progress
	@echo "Check des dépendances : un controller HTTP ne peut pas appeler un Repository"
	@$(EXEC_PHP) vendor/bin/deptrac analyse --config-file=quality-rules/Deptrac/deptrac.no-repository-in-http.yaml --no-progress
	@echo "Check des dépendances : un Builder ne peut pas être appelé dans src"
	@$(EXEC_PHP) vendor/bin/deptrac analyse --config-file=quality-rules/Deptrac/deptrac.no-builder-in-src.yaml --no-progress
	@echo "Check des dépendances : un test de handler ne touche ni base, ni kernel, ni Infrastructure"
	@$(EXEC_PHP) vendor/bin/deptrac analyse --config-file=quality-rules/Deptrac/deptrac.no-database-in-handler-tests.yaml --no-progress

phpstan: ## Run PHPStan static analysis
	@echo "🔍 Check PHPStan..."
	@$(EXEC_PHP) vendor/bin/phpstan analyse --memory-limit=1G --no-progress

api-doc-validate: ## Validate that the API documentation generates without errors
	@echo "🔍 Validation de la doc API OpenAPI..."
	@$(EXEC_PHP) bin/console nelmio:apidoc:dump --format=json > /dev/null
	@echo "✅ La documentation API est valide."

api-doc-coverage: ## Check that every API controller has OpenAPI documentation
	@$(EXEC_PHP) php bin/check-api-doc-coverage

openapi: ## Regenerate the versioned API contract docs/api/openapi.json (ADR 0007 §15)
	@echo "📄 Génération du contrat OpenAPI..."
	@$(EXEC_PHP) bin/console nelmio:apidoc:dump --format=json > docs/api/openapi.json
	@echo "✅ docs/api/openapi.json régénéré : relis le diff (un changement de contrat est une décision)."

openapi-check: ## Fail if docs/api/openapi.json is not the current API contract
	@$(EXEC_PHP) bin/console nelmio:apidoc:dump --format=json | diff -u docs/api/openapi.json - > /dev/null \
		|| { echo "❌ docs/api/openapi.json ne correspond plus à l'API : lance 'make openapi' et relis le diff."; exit 1; }
	@echo "✅ Le contrat OpenAPI versionné est à jour."

jwt-keys: ## Generate the JWT key pair if missing (config/jwt, git-ignored)
	@$(EXEC_PHP) bin/console lexik:jwt:generate-keypair --skip-if-exists

quality: deptrac phpstan api-doc-validate api-doc-coverage openapi-check ## Run architecture, static analysis and API doc checks

deploy-smoke: ## Essai local de la configuration de production (deploy/) ; FRONT_IMAGE=<image du front> pour essayer aussi le SPA (ADR 0017)
	@docker build --target frankenphp_prod -t repas-smoke-back .
	@BACK_IMAGE=repas-smoke-back FRONT_IMAGE=$(FRONT_IMAGE) bash deploy/smoke-test.sh

audit: ## Check composer.lock against known security advisories (ADR 0014)
	@$(EXEC_PHP) composer audit --locked

outdated: ## List direct dependencies with a newer version, majors included (ADR 0014)
	@$(EXEC_PHP) composer outdated --direct

sql-budget-update: ## Régénère les budgets SQL des use cases d'écriture après un allègement (tests/Repas/WriteBudget)
	@$(DOCKER_COMPOSE) exec -T -e UPDATE_SQL_BUDGETS=1 php vendor/bin/phpunit --no-coverage tests/Repas/WriteBudget

drive-samples: ## Régénère docs/drive/samples/*.sample.json depuis les réponses réelles de l'API Drive (DRV-501) : relis le diff
	@$(DOCKER_COMPOSE) exec -T -e UPDATE_DRIVE_SAMPLES=1 php vendor/bin/phpunit --no-coverage tests/Drive/Contract
	@echo "✅ docs/drive/samples régénéré : relis le diff (le front fige ce contrat)."


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
.PHONY: fixtures-demo fixtures-load-test fixtures-load-dev fixtures-load-append-dev fixtures-load-append-test

fixtures-demo: ## Create the demo accounts and data (admin@repas.fr, user@repas.fr / "M0t 2 p@sse"): dev only, idempotent, keeps your data
	@$(EXEC_PHP) bin/console doctrine:fixtures:load --env=dev --group=demo --append -n
	@echo "✅ Comptes de démo prêts : admin@repas.fr (administrateur) et user@repas.fr, mot de passe : M0t 2 p@sse"

fixtures-load-test: ## Load fixtures into the test database
	@$(EXEC_PHP) bin/console doctrine:fixtures:load --env=test -n

fixtures-load-dev: ## Load fixtures into the development database
	@$(EXEC_PHP) bin/console doctrine:fixtures:load --env=dev -n

fixtures-load-append-dev: ## Append the fixtures instead of flushing the database
	@$(EXEC_PHP) bin/console doctrine:fixtures:load --env=dev --append -n

fixtures-load-append-test: ## Append the fixtures instead of flushing the database
	@$(EXEC_PHP) bin/console doctrine:fixtures:load --env=test --append -n

