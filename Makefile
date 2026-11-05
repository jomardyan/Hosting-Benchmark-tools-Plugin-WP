# GNU Make; Git Bash is required on Windows for existing shell recipes.
ifeq ($(OS),Windows_NT)
BASH ?= C:/Program Files/Git/bin/bash.exe
else
BASH ?= /bin/bash
endif
SHELL := $(BASH)
.DEFAULT_GOAL := help
PYTHON ?= python
PHP ?= php
GIT ?= git
GITLEAKS ?= gitleaks
SOURCE_ZIP ?= dist/source.zip

PLUGIN_SLUG  ?= wp-hosting-benchmark
MAIN_FILE    ?= wp-hosting-benchmark.php
OUTPUT_DIR   ?= dist
VERSION_PART ?= patch
NEW_VERSION  ?=



build: zip ## Build the current private plugin version without bumping source files.
check: repository-check ## Run all local checks.

POWERSHELL ?= powershell

help: ## List commands without building or changing versions
	@"$(PYTHON)" tools/make_support.py help
bump: ## Explicitly bump VERSION_PART or set NEW_VERSION using the existing script
ifeq ($(OS),Windows_NT)
	"$(POWERSHELL)" -NoProfile -File scripts/bump-version.ps1 -MainFile "$(MAIN_FILE)" -VersionPart "$(VERSION_PART)" -NewVersion "$(NEW_VERSION)"
else
	"$(BASH)" scripts/bump-version.sh "$(MAIN_FILE)" readme.txt "$(VERSION_PART)" "$(NEW_VERSION)"
endif
zip: ## Build the existing private plugin ZIP without changing the version
	"$(PYTHON)" tools/make_support.py guard "$(OUTPUT_DIR)"
ifeq ($(OS),Windows_NT)
	"$(POWERSHELL)" -NoProfile -File scripts/build-plugin.ps1 -PluginSlug "$(PLUGIN_SLUG)" -MainFile "$(MAIN_FILE)" -OutputDir "$(OUTPUT_DIR)"
else
	"$(BASH)" scripts/build-plugin.sh "$(PLUGIN_SLUG)" "$(MAIN_FILE)" "$(OUTPUT_DIR)"
endif
lint: ## Run the repository PHP and JavaScript syntax checks
	"$(PYTHON)" tools/check_sources.py
version: ## Read the plugin header without shell grep
	"$(PYTHON)" tools/make_support.py version "$(MAIN_FILE)"
dist: ## List local build outputs
	"$(PYTHON)" -c "from pathlib import Path; import sys; p=Path(sys.argv[1]); print('\n'.join(str(x) for x in sorted(p.glob('*'))))" "$(OUTPUT_DIR)"
clean: ## Remove only guarded generated outputs
	"$(PYTHON)" tools/make_support.py clean "$(OUTPUT_DIR)" "$(SOURCE_ZIP)"

package: ## Archive committed sources into SOURCE_ZIP (not an installable binary)
	"$(PYTHON)" tools/make_support.py source-package "$(SOURCE_ZIP)" --git "$(GIT)"

install: ## Install declared development dependencies
	"$(PHP)" --version

dev: ## Show local development setup guidance
	@echo "Install this plugin in a local WordPress/WooCommerce environment; see README.md."

test: ## Run the project regression suite
	"$(PHP)" tests/regression.php

security: ## Run the project security/dependency checks
	"$(GITLEAKS)" git --redact --no-banner

audit-lint: makefile-check ## Run the CI source quality checks
	"$(PYTHON)" tools/check_sources.py

audit-test: ## Run the complete CI regression suite
	"$(PHP)" tests/regression.php

audit-build: ## Run the CI build checks
	"$(PYTHON)" tools/check_sources.py

audit-security: ## Run the CI security checks
	"$(GITLEAKS)" git --redact --no-banner

repository-check: audit-lint audit-test audit-build ## Run all existing CI lint, test and build checks

audit-install: ## Install the locked CI dependencies
	"$(PHP)" --version

wordpress-org-package: ## Build the audited installable WordPress directory edition
	"$(PYTHON)" tools/build_wordpress_org.py

wordpress-org-check: ## Validate translation contracts and build the directory edition
	"$(PYTHON)" tools/test_directory_domains.py
	"$(PYTHON)" tools/build_wordpress_org.py

source-package: package ## Alias for the committed-source archive


makefile-check: ## Test output safety, path spaces and source-archive integrity
	"$(PYTHON)" tools/test_make_support.py

.PHONY: build check help bump zip lint version dist \
	clean package install dev test security audit-lint audit-test \
	audit-build audit-security repository-check audit-install wordpress-org-package wordpress-org-check source-package makefile-check
