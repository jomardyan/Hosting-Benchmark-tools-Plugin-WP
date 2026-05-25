PLUGIN_SLUG  ?= wp-hosting-benchmark
MAIN_FILE    ?= wp-hosting-benchmark.php
OUTPUT_DIR   ?= dist
VERSION_PART ?= patch
NEW_VERSION  ?=

.DEFAULT_GOAL := help

.PHONY: help build bump zip clean lint check version dist

build: bump zip ## Bump the plugin version, then build the distributable ZIP.
check: lint     ## Run all local checks.

# ──────────────────────────────────────────────
# Windows — PowerShell recipes
# ──────────────────────────────────────────────
ifeq ($(OS),Windows_NT)
POWERSHELL ?= powershell

help: ## Show available make targets.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -Command "$$targets = Select-String -Path '$(MAKEFILE_LIST)' -Pattern '^[a-zA-Z0-9_-]+:.*?## ' | ForEach-Object { $$name, $$desc = $$_.Line -split ':.*?## ', 2; [pscustomobject]@{Target=$$name; Description=$$desc} }; $$targets | Format-Table -AutoSize"

bump: ## Increase plugin version. Use VERSION_PART=patch|minor|major or NEW_VERSION=x.y.z.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -File scripts/bump-version.ps1 -MainFile "$(MAIN_FILE)" -VersionPart "$(VERSION_PART)" -NewVersion "$(NEW_VERSION)"

zip: ## Create dist/<plugin-slug>-<version>.zip.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -File scripts/build-plugin.ps1 -PluginSlug "$(PLUGIN_SLUG)" -MainFile "$(MAIN_FILE)" -OutputDir "$(OUTPUT_DIR)"

lint: ## Run php -l against distributable plugin PHP files.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -Command "$$php = Get-Command php -ErrorAction SilentlyContinue; if (-not $$php) { throw 'PHP is not installed or is not on PATH.' }; $$files = @(); foreach ($$path in @('$(MAIN_FILE)', 'uninstall.php')) { if (Test-Path -LiteralPath $$path) { $$files += Get-Item -LiteralPath $$path } }; if (Test-Path -LiteralPath 'src') { $$files += Get-ChildItem -LiteralPath 'src' -Recurse -File -Filter '*.php' }; if (-not $$files) { Write-Host 'No PHP files found.'; exit 0 }; foreach ($$file in $$files) { & $$php.Source -l $$file.FullName; if ($$LASTEXITCODE -ne 0) { exit $$LASTEXITCODE } }"

version: ## Print the plugin version from the main plugin file.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -Command "$$content = Get-Content -LiteralPath '$(MAIN_FILE)' -Raw; $$match = [regex]::Match($$content, '(?m)^[\s\/*#@]*Version:\s*(.+)$$'); if (-not $$match.Success) { throw 'Could not determine plugin version from $(MAIN_FILE)' }; $$match.Groups[1].Value.Trim()"

dist: ## List generated build artifacts.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -Command "if (Test-Path -LiteralPath '$(OUTPUT_DIR)') { Get-ChildItem -LiteralPath '$(OUTPUT_DIR)' | Format-Table -AutoSize } else { Write-Host 'No $(OUTPUT_DIR) directory found.' }"

clean: ## Remove generated build artifacts.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -Command "if (Test-Path -LiteralPath '$(OUTPUT_DIR)') { Remove-Item -LiteralPath '$(OUTPUT_DIR)' -Recurse -Force; Write-Host 'Removed $(OUTPUT_DIR).' } else { Write-Host 'Nothing to clean.' }"

# ──────────────────────────────────────────────
# Linux / macOS — bash recipes
# ──────────────────────────────────────────────
else
SHELL := /bin/bash

help: ## Show available make targets.
	@grep -E '^[a-zA-Z0-9_-]+:.*?## ' $(MAKEFILE_LIST) | \
	  awk -F ':.*?## ' '!seen[$$1]++ {printf "%-15s %s\n", $$1, $$2}'

bump: ## Increase plugin version. Use VERSION_PART=patch|minor|major or NEW_VERSION=x.y.z.
	@bash scripts/bump-version.sh "$(MAIN_FILE)" readme.txt "$(VERSION_PART)" "$(NEW_VERSION)"

zip: ## Create dist/<plugin-slug>-<version>.zip.
	@bash scripts/build-plugin.sh "$(PLUGIN_SLUG)" "$(MAIN_FILE)" "$(OUTPUT_DIR)"

lint: ## Run php -l against distributable plugin PHP files.
	@command -v php >/dev/null 2>&1 || { echo "PHP is not installed or is not on PATH." >&2; exit 1; }; \
	result=0; \
	for f in $$([ -f "$(MAIN_FILE)" ] && echo "$(MAIN_FILE)"; \
	            [ -f "uninstall.php" ] && echo "uninstall.php"; \
	            [ -d src ] && find src -name "*.php" || true); do \
	  php -l "$$f" || result=$$?; \
	done; \
	exit $$result

version: ## Print the plugin version from the main plugin file.
	@grep -m1 'Version:' "$(MAIN_FILE)" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]'

dist: ## List generated build artifacts.
	@if [ -d "$(OUTPUT_DIR)" ]; then ls -lh "$(OUTPUT_DIR)"; \
	else echo "No $(OUTPUT_DIR) directory found."; fi

clean: ## Remove generated build artifacts.
	@if [ -d "$(OUTPUT_DIR)" ]; then rm -rf "$(OUTPUT_DIR)"; echo "Removed $(OUTPUT_DIR)."; \
	else echo "Nothing to clean."; fi

endif
