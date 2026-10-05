# Behavioral characterization harness for Kadupul main on PHP 8.4.
#
# The harness runs the application in containers and records normalized
# observations. Golden files are the compatibility specification a Kadupul
# rewrite must satisfy; they never update as a side effect of running tests.

PHP_VERSION ?= 8.4
TARGET      ?= kadupul
BEHAVIOR    := PHP_VERSION=$(PHP_VERSION) ./tests/bin/behavior

.DEFAULT_GOAL := help

.PHONY: help
help: ## Show available targets
	@grep -hE '^[a-zA-Z_ -]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS=":.*?## "}; {printf "  %-26s %s\n", $$1, $$2}'

.PHONY: test
test: test-characterization ## Run the behavioral suite

# composer test runs the Symfony PHPUnit suite. Pest installs separately
# from tests/composer.json; see tests/README.md for all runner boundaries.

.PHONY: test-symfony test-legacy test-database test-unit-coverage test-javascript test-themes test-runner-contracts
test-symfony: ## Run the Symfony suite with application PHPUnit 10
	./tests/bin/run symfony
test-legacy: ## Run the explicit legacy regression suites with Pest 4
	./tests/bin/run legacy
test-database: ## Run database contracts against a prepared disposable database
	./tests/bin/run database
test-unit-coverage: ## Run the unit contribution to coverage (requires PCOV or Xdebug)
	./tests/bin/run unit-coverage
test-javascript: ## Run all native Unit JavaScript contracts
	./tests/bin/run javascript
test-themes: ## Run the local theme browser suite with its explicit JS config
	./tests/bin/run themes
test-runner-contracts: ## Verify runner dispatch and unavailable/empty-suite failures
	mise exec -- python tests/tools/test_suite_runner.py

.PHONY: test-characterization
test-characterization: ## Verify observed behavior against the committed goldens
	$(BEHAVIOR) --target $(TARGET)

.PHONY: test-update-golden
test-update-golden: ## Re-record goldens. Review the diff before committing.
	$(BEHAVIOR) --target $(TARGET) --update-golden

.PHONY: test-bootstrap-golden
test-bootstrap-golden: ## Capture added scenarios/runtimes; incomplete inventories cannot be compared.
	$(BEHAVIOR) --target $(TARGET) --update-golden --bootstrap-goldens

.PHONY: test-keep
test-keep: ## Run the behavioral suite and leave containers up for inspection
	$(BEHAVIOR) --target $(TARGET) --keep

# Scenario-scoped targets. The harness runs one ordered pass because later
# scenarios consume fixtures created by earlier ones, so these report the
# subset rather than running it in isolation.
.PHONY: test-api test-poller test-plugins test-auth test-devices
test-api: SCOPE := api
test-poller: SCOPE := poller graphs
test-plugins: SCOPE := plugins
test-auth: SCOPE := auth
test-devices: SCOPE := devices
test-api test-poller test-plugins test-auth test-devices: ## Run the suite, reporting one scenario group
	$(BEHAVIOR) --target $(TARGET) --only $(SCOPE)

.PHONY: compare
compare: ## Differential report. Usage: make compare BASELINE=cacti-1.2.31 CANDIDATE=kadupul
	@test -n "$(BASELINE)" -a -n "$(CANDIDATE)" \
		|| { echo 'Usage: make compare BASELINE=<target> CANDIDATE=<target>'; exit 2; }
	./tests/bin/compare --baseline $(BASELINE) --candidate $(CANDIDATE) $(if $(APPROVALS),--approvals $(APPROVALS)) $(if $(RESULTS_ROOT),--results-root "$(RESULTS_ROOT)")

.PHONY: test-harness-selftest
test-harness-selftest: ## Check the harness normalization does not erase real contracts
	mise exec python@3.12 -- python tests/Support/Behavior/selftest.py

.PHONY: inventory
inventory: ## Regenerate the behavioral surface inventory
	mise exec python@3.12 -- python tests/Support/Behavior/inventory.py

.PHONY: clean
clean: ## Remove harness results and stop any stray compose projects
	rm -rf tests/behavior/results
	@docker compose -p kadupul-behavior -f tests/behavior/compose.yml down --volumes --remove-orphans 2>/dev/null || true
	@# Older runs used a per-pid project name; clean those up too.
	@docker compose ls --all --format json 2>/dev/null \
		| grep -o '"Name":"kadupul-behavior[^"]*"' \
		| cut -d'"' -f4 \
		| xargs -I{} docker compose -p {} -f tests/behavior/compose.yml down --volumes --remove-orphans 2>/dev/null || true
