# CodeDNA — developer commands
#
# Docker development environment (Phase 02) plus repository checks.
# Run `make help` for the list of targets.

SHELL := /bin/bash
.DEFAULT_GOAL := help

# Tool versions — keep in sync with .github/workflows/ci.yml
MARKDOWNLINT_CLI2_VERSION := 0.23.3
YAMLLINT_VERSION          := 1.38.0
ACTIONLINT_PY_VERSION     := 1.7.12.25
SHELLCHECK_PY_VERSION     := 0.11.0.1
HADOLINT_PY_VERSION       := 2.15.1.2
GITLEAKS_VERSION          := v8.30.1

COMPOSE := docker compose

# Placeholder values that only let `docker compose config` interpolate the
# file in CI/checks; nothing is started with them.
COMPOSE_CHECK_ENV := APP_KEY=check DB_PASSWORD=check MINIO_ROOT_PASSWORD=check \
	SOURCE_STORAGE_ACCESS_KEY_ID=check SOURCE_STORAGE_SECRET_ACCESS_KEY=check \
	ANALYZER_HMAC_SECRET=check

.PHONY: help
help: ## Show available targets
	@grep -E '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# ---------------------------------------------------------------------------
# Docker development environment
# ---------------------------------------------------------------------------
.PHONY: setup
setup: ## One-time setup: create .env with local secrets, build images
	./scripts/setup.sh
	$(COMPOSE) build

.PHONY: build
build: ## Rebuild the development images
	$(COMPOSE) build

.PHONY: up
up: ## Start all services, wait until healthy, apply database migrations
	$(COMPOSE) up -d --wait
	$(MAKE) --no-print-directory migrate

.PHONY: migrate
migrate: ## Apply Laravel database migrations (development database)
	$(COMPOSE) exec -T backend php artisan migrate --no-interaction

.PHONY: down
down: ## Stop and remove containers (named volumes/data are kept)
	$(COMPOSE) down

.PHONY: ps
ps: ## Show service status and health
	$(COMPOSE) ps -a

.PHONY: logs
logs: ## Follow logs (all services, or one: make logs s=backend)
	$(COMPOSE) logs -f --tail=100 $(s)

.PHONY: shell-backend
shell-backend: ## Open a shell in the backend (Laravel) container
	$(COMPOSE) exec backend bash

.PHONY: shell-frontend
shell-frontend: ## Open a shell in the frontend (Next.js) container
	$(COMPOSE) exec frontend bash

.PHONY: shell-analyzer
shell-analyzer: ## Open a shell in the analyzer container
	$(COMPOSE) exec analyzer bash

.PHONY: test
test: ## Run all test suites inside the running containers
	$(COMPOSE) exec -T analyzer pytest
	./scripts/ensure-test-database.sh
	$(COMPOSE) exec -T backend vendor/bin/phpunit
	$(COMPOSE) exec -T frontend npm test

.PHONY: lint-backend
lint-backend: ## Check backend code style (Laravel Pint) in the running container
	$(COMPOSE) exec -T backend vendor/bin/pint --test

.PHONY: lint-analyzer
lint-analyzer: ## Lint (ruff), check formatting and type-check (mypy --strict) the analyzer in the running container
	$(COMPOSE) exec -T analyzer ruff check .
	$(COMPOSE) exec -T analyzer ruff format --check .
	$(COMPOSE) exec -T analyzer mypy

.PHONY: lint-frontend
lint-frontend: ## Lint (ESLint) and type-check (tsc) the frontend in the running container
	$(COMPOSE) exec -T frontend npm run lint
	$(COMPOSE) exec -T frontend npm run typecheck

.PHONY: verify
verify: ## Runtime smoke test of the running environment (routing, networking, storage)
	./scripts/verify-infra.sh

# ---------------------------------------------------------------------------
# Repository checks (same checks as CI)
# ---------------------------------------------------------------------------
.PHONY: check
check: check-repo lint-docs lint-yaml lint-workflows lint-shell lint-docker compose-config ## Run all static checks

.PHONY: check-repo
check-repo: ## Required files, Markdown links/anchors, .env.example hygiene
	python3 scripts/check_repo.py

.PHONY: lint-docs
lint-docs: ## Lint Markdown (requires Node/npx)
	npx --yes markdownlint-cli2@$(MARKDOWNLINT_CLI2_VERSION) "**/*.md"

.PHONY: lint-yaml
lint-yaml: ## Lint YAML (requires yamllint)
	yamllint --strict .

.PHONY: lint-workflows
lint-workflows: ## Lint GitHub Actions workflows (requires actionlint)
	actionlint

.PHONY: lint-shell
lint-shell: ## Lint shell scripts (requires shellcheck)
	shellcheck scripts/*.sh docker/*/*.sh

.PHONY: lint-docker
lint-docker: ## Lint Dockerfiles (requires hadolint)
	hadolint docker/*/Dockerfile

.PHONY: compose-config
compose-config: ## Validate docker-compose.yml without starting anything
	$(COMPOSE_CHECK_ENV) $(COMPOSE) --env-file .env.example config --quiet

.PHONY: scan-secrets
scan-secrets: ## Scan git history and uncommitted changes for secrets (requires gitleaks)
	gitleaks git --redact --no-banner .
	gitleaks git --pre-commit --redact --no-banner .
