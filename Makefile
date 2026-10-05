# CodeDNA — developer commands
#
# Phase 01 provides repository/documentation checks and the backing services
# (PostgreSQL, Redis). Application targets (backend, frontend, analyzer tests)
# are added in the phases that create those applications.

SHELL := /bin/bash
.DEFAULT_GOAL := help

# Tool versions — keep in sync with .github/workflows/ci.yml
MARKDOWNLINT_CLI2_VERSION := 0.23.3
YAMLLINT_VERSION          := 1.38.0
ACTIONLINT_PY_VERSION     := 1.7.12.25
GITLEAKS_VERSION          := v8.30.1

COMPOSE := docker compose

.PHONY: help
help: ## Show available targets
	@grep -E '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

# ---------------------------------------------------------------------------
# Setup
# ---------------------------------------------------------------------------
.PHONY: env
env: ## Create .env from .env.example if it does not exist
	@if [ -f .env ]; then echo ".env already exists — not overwriting"; \
	else cp .env.example .env && echo "Created .env — now set DB_PASSWORD (and other secrets as phases require)"; fi

# ---------------------------------------------------------------------------
# Repository checks (same checks as CI)
# ---------------------------------------------------------------------------
.PHONY: check
check: check-repo lint-docs lint-yaml lint-workflows compose-config ## Run all foundation checks

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

.PHONY: compose-config
compose-config: ## Validate docker-compose.yml without starting anything
	DB_PASSWORD=compose-config-check $(COMPOSE) --env-file .env.example config --quiet

.PHONY: scan-secrets
scan-secrets: ## Scan git history and working tree for secrets (requires gitleaks)
	gitleaks git --redact --no-banner .
	gitleaks dir --redact --no-banner .

# ---------------------------------------------------------------------------
# Backing services (PostgreSQL 16, Redis 7)
# ---------------------------------------------------------------------------
.PHONY: infra-up
infra-up: ## Start PostgreSQL and Redis (requires .env with DB_PASSWORD)
	$(COMPOSE) up -d --wait

.PHONY: infra-down
infra-down: ## Stop backing services (data volumes are kept)
	$(COMPOSE) down

.PHONY: infra-ps
infra-ps: ## Show backing service status
	$(COMPOSE) ps
