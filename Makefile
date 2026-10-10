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
PIP_AUDIT                 ?= pipx run pip-audit==2.9.0

COMPOSE := docker compose

# Placeholder values that only let `docker compose config` interpolate the
# file in CI/checks; nothing is started with them.
COMPOSE_CHECK_ENV := APP_KEY=check DB_PASSWORD=check REDIS_PASSWORD=check MINIO_ROOT_PASSWORD=check \
	SOURCE_STORAGE_ACCESS_KEY_ID=check SOURCE_STORAGE_SECRET_ACCESS_KEY=check \
	ANALYZER_HMAC_SECRET=check

# The same for docker-compose.prod.yml (Phase 25): placeholders only, so the
# production model can be rendered and checked; never deployable values.
PROD_CHECK_ENV := APP_VERSION=check CODEDNA_DOMAIN=codedna.example APP_KEY=check DB_PASSWORD=check \
	REDIS_PASSWORD=check SOURCE_STORAGE_ACCESS_KEY_ID=check SOURCE_STORAGE_SECRET_ACCESS_KEY=check \
	MINIO_ROOT_USER=check MINIO_ROOT_PASSWORD=check ANALYZER_HMAC_SECRET=check \
	TLS_CERTIFICATE_FILE=/dev/null TLS_PRIVATE_KEY_FILE=/dev/null
PROD_COMPOSE := docker compose -f docker-compose.prod.yml --env-file .env.production.example

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
test: ## Run all test suites inside the running containers (backend in the queue worker: it reaches the analyzer)
	$(COMPOSE) exec -T analyzer pytest
	./scripts/ensure-test-database.sh
	$(COMPOSE) exec -T -e CODEDNA_REQUIRE_EVALUATOR=1 queue vendor/bin/phpunit
	$(COMPOSE) exec -T frontend npm test
	$(COMPOSE) exec -T -e CODEDNA_REQUIRE_SANDBOX=1 evaluator python3 -m unittest discover -s /opt/evaluator/tests -t /opt/evaluator

.PHONY: lint-backend
lint-backend: ## Check backend code style (Laravel Pint) in the running container
	$(COMPOSE) exec -T backend vendor/bin/pint --test

.PHONY: lint-analyzer
lint-analyzer: ## Lint (ruff), check formatting and type-check (mypy --strict) the analyzer in the running container
	$(COMPOSE) exec -T analyzer ruff check .
	$(COMPOSE) exec -T analyzer ruff format --check .
	$(COMPOSE) exec -T analyzer mypy

.PHONY: test-evaluator
test-evaluator: ## Run the evaluator's protocol and sandbox security tests inside the running evaluator container
	$(COMPOSE) exec -T -e CODEDNA_REQUIRE_SANDBOX=1 evaluator python3 -m unittest discover -s /opt/evaluator/tests -t /opt/evaluator

.PHONY: lint-evaluator
lint-evaluator: ## Lint (ruff), check formatting and type-check (mypy --strict) the evaluator with the analyzer's tools
	$(COMPOSE) run --rm --no-deps -T -v ./evaluator:/evaluator:ro -w /evaluator --entrypoint sh analyzer -c 'ruff check . && ruff format --check . && mypy'

.PHONY: lint-frontend
lint-frontend: ## Lint (ESLint) and type-check (tsc) the frontend in the running container
	$(COMPOSE) exec -T frontend npm run lint
	$(COMPOSE) exec -T frontend npm run typecheck

.PHONY: build-frontend
build-frontend: ## Production build of the frontend (catches server/client boundary and route errors tsc misses)
	$(COMPOSE) exec -T frontend npm run build

.PHONY: audit
audit: ## Dependency vulnerability audits (needs internet): Composer, npm runtime packages, analyzer Python packages
	$(COMPOSE) exec -T backend composer audit --locked
	$(COMPOSE) exec -T frontend npm audit --omit=dev
	$(PIP_AUDIT) --require-hashes --disable-pip -r analyzer/requirements.txt
	$(PIP_AUDIT) --require-hashes --disable-pip -r analyzer/requirements-dev.txt

.PHONY: verify
verify: ## Runtime smoke test of the running environment (routing, networking, storage)
	./scripts/verify-infra.sh

# ---------------------------------------------------------------------------
# Repository checks (same checks as CI)
# ---------------------------------------------------------------------------
.PHONY: check
check: check-repo check-contracts lint-docs lint-yaml lint-workflows lint-shell lint-docker compose-config prod-config ## Run all static checks

.PHONY: check-repo
check-repo: ## Required files, Markdown links/anchors, .env.example hygiene
	python3 scripts/check_repo.py

.PHONY: check-contracts
check-contracts: ## Cross-service contract parity: error codes, evaluator statuses, shared HMAC vector
	python3 scripts/check_contracts.py

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
	shellcheck scripts/*.sh scripts/benchmark/*.sh docker/*/*.sh docker/nginx/production/entrypoint/*.sh

.PHONY: lint-docker
lint-docker: ## Lint Dockerfiles (requires hadolint)
	hadolint docker/*/Dockerfile docker/nginx/production/Dockerfile

.PHONY: compose-config
compose-config: ## Validate docker-compose.yml without starting anything
	$(COMPOSE_CHECK_ENV) $(COMPOSE) --env-file .env.example config --quiet

# ---------------------------------------------------------------------------
# Production profile (Phase 25, docs/operations/production-deployment.md).
# Nothing here deploys or needs a real credential.
# ---------------------------------------------------------------------------
.PHONY: prod-config
prod-config: ## Render docker-compose.prod.yml (alone and with the enterprise external-service overlays) and check the production baseline
	@mkdir -p tmp
	$(PROD_CHECK_ENV) $(PROD_COMPOSE) --profile migrate --profile local-ai config --format json > tmp/prod-compose.json
	python3 scripts/check_production.py tmp/prod-compose.json
	$(PROD_CHECK_ENV) docker compose -f docker-compose.prod.yml $(addprefix -f ,$(wildcard docker/enterprise/compose.external-*.yml)) \
		--env-file .env.production.example --profile migrate --profile local-ai config --format json > tmp/prod-compose-external.json
	python3 scripts/check_production.py --external tmp/prod-compose-external.json

.PHONY: prod-build
prod-build: ## Build every production image (tag: APP_VERSION, default "local")
	@# Read the caller's APP_VERSION first: PROD_CHECK_ENV sets APP_VERSION=check,
	@# and a later prefix assignment would expand that placeholder instead.
	version=$${APP_VERSION:-local}; $(PROD_CHECK_ENV) APP_VERSION=$$version $(PROD_COMPOSE) --profile migrate build

.PHONY: prod-smoke
prod-smoke: ## Production-like smoke test: build, start with throwaway values on loopback, check, remove
	./scripts/smoke-production.sh

.PHONY: prod-evaluator-attest
prod-evaluator-attest: ## On a production host: show the isolation the evaluator image attests under runsc
	docker run --rm --runtime=$${EVALUATOR_RUNTIME:-runsc} --network none --entrypoint python3 \
		codedna-evaluator:$${APP_VERSION:?set APP_VERSION} -c 'from evaluator import isolation; print(isolation.detect())'

# ---------------------------------------------------------------------------
# Performance (Phase 26, docs/performance/benchmarking.md). Everything runs
# against a dedicated database (codedna_benchmark), Redis databases 5 and 6,
# and the benchmark/ storage prefix; development data is never touched.
# ---------------------------------------------------------------------------
BENCH_SCALE ?= small
BENCH_ENV := -e DB_DATABASE=codedna_benchmark -e REDIS_DB=5 -e REDIS_CACHE_DB=6 -e SOURCE_STORAGE_PREFIX=benchmark/ -e LOG_LEVEL=warning
BENCH_SOURCES := backend/storage/app/benchmark/sources

.PHONY: benchmark-sources
benchmark-sources: ## Generate the deterministic synthetic archives used by the benchmarks
	@mkdir -p $(BENCH_SOURCES) backend/storage/app/benchmark/results
	python3 scripts/benchmark/make_sources.py $(BENCH_SOURCES)
	@chmod -R a+rwX backend/storage/app/benchmark

.PHONY: benchmark-seed
benchmark-seed: benchmark-sources ## Create codedna_benchmark and seed it (BENCH_SCALE=small|medium|large)
	$(COMPOSE) exec -T postgres psql -U codedna -d codedna -qc "DROP DATABASE IF EXISTS codedna_benchmark" -c "CREATE DATABASE codedna_benchmark"
	$(COMPOSE) exec -T $(BENCH_ENV) queue php artisan migrate --force
	$(COMPOSE) exec -T $(BENCH_ENV) queue php artisan benchmark:seed --scale=$(BENCH_SCALE) --sources=storage/app/benchmark/sources

.PHONY: benchmark-clean
benchmark-clean: ## Remove every benchmark trace: stored objects, the database, Redis databases 5 and 6, local results
	-$(COMPOSE) exec -T $(BENCH_ENV) queue php artisan benchmark:clean
	$(COMPOSE) exec -T postgres psql -U codedna -d codedna -qc "DROP DATABASE IF EXISTS codedna_benchmark"
	$(COMPOSE) exec -T redis sh -c 'redis-cli -n 5 flushdb >/dev/null; redis-cli -n 6 flushdb >/dev/null'
	rm -rf backend/storage/app/benchmark

.PHONY: benchmark-api
benchmark-api: ## In-process API benchmark on the benchmark database (latency, queries, response sizes; BENCH_API_ARGS="--filter=x --show-queries")
	@mkdir -p backend/storage/app/benchmark/results && chmod a+rwX backend/storage/app/benchmark/results
	$(COMPOSE) exec -T $(BENCH_ENV) queue php artisan benchmark:api --iterations=30 --json=storage/app/benchmark/results/api.json $(BENCH_API_ARGS)

.PHONY: benchmark-queue
benchmark-queue: benchmark-sources ## Real workers and analyzer: enqueue, queue wait, execution, throughput at 1, 2, 4 workers
	$(COMPOSE) exec -T $(BENCH_ENV) queue php artisan benchmark:queue --workers=1,2,4 --analyses=24 --archive=storage/app/benchmark/sources/medium.zip

.PHONY: benchmark-analyzer
benchmark-analyzer: benchmark-sources ## Analyzer stages, peak memory and concurrency, in a container with the service's limits
	$(COMPOSE) run --rm --no-deps -T -v ./scripts/benchmark:/bench:ro -v ./$(BENCH_SOURCES):/sources:ro analyzer python /bench/analyzer_bench.py /sources

.PHONY: benchmark-intake
benchmark-intake: benchmark-sources ## Upload and GitHub import time and memory up to the 50 MiB archive limit
	$(COMPOSE) exec -T queue vendor/bin/phpunit tests/Benchmark

.PHONY: loadtest
loadtest: ## HTTP load test of scenarios A-F through Nginx with the production backend image (needs benchmark-seed)
	./scripts/benchmark/loadtest.sh $(LOADTEST_ARGS)

.PHONY: scan-secrets
scan-secrets: ## Scan git history and uncommitted changes for secrets (requires gitleaks)
	gitleaks git --redact --no-banner .
	gitleaks git --pre-commit --redact --no-banner .
