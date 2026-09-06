# WHMCS WordPress Theme Provisioner

PHP ?= php

.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

.PHONY: test
test: ## Run the unit and integration suites
	@$(PHP) bin/run-tests.php

.PHONY: test-all
test-all: ## Run everything, including the end-to-end scenarios
	@$(PHP) bin/run-tests.php unit integration e2e

.PHONY: e2e
e2e: ## Run the end-to-end suite only
	@$(PHP) bin/run-tests.php e2e

.PHONY: lint
lint: ## Syntax-check every PHP file
	@find shared whmcs wordpress bootstrap bin tests -name '*.php' -print0 \
		| xargs -0 -n1 $(PHP) -l > /dev/null && echo "PHP OK"
	@find infrastructure -name '*.sh' -exec bash -n {} \; && echo "shell OK"

.PHONY: build
build: ## Assemble dist/
	@$(PHP) bin/build.php

.PHONY: clean
clean: ## Remove build output
	@rm -rf dist
	@echo "cleaned"

.PHONY: keygen
keygen: ## Print a new master key
	@$(PHP) bin/wtp-keygen.php

.PHONY: check
check: lint test-all ## Lint and run every test
