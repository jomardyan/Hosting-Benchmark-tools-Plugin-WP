PLUGIN_SLUG ?= wp-hosting-benchmark
MAIN_FILE ?= wp-hosting-benchmark.php
OUTPUT_DIR ?= dist
POWERSHELL ?= powershell
VERSION_PART ?= patch
NEW_VERSION ?=

.DEFAULT_GOAL := help

.PHONY: help build bump zip clean lint check version dist

help: ## Show available make targets.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -Command "$$targets = Select-String -Path '$(MAKEFILE_LIST)' -Pattern '^[a-zA-Z0-9_-]+:.*?## ' | ForEach-Object { $$name, $$desc = $$_.Line -split ':.*?## ', 2; [pscustomobject]@{Target=$$name; Description=$$desc} }; $$targets | Format-Table -AutoSize"

build: bump zip ## Bump the plugin version, then build the distributable ZIP.

bump: ## Increase plugin version. Use VERSION_PART=patch|minor|major or NEW_VERSION=x.y.z.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -File scripts/bump-version.ps1 -MainFile "$(MAIN_FILE)" -VersionPart "$(VERSION_PART)" -NewVersion "$(NEW_VERSION)"

zip: ## Create dist/<plugin-slug>-<version>.zip.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -File scripts/build-plugin.ps1 -PluginSlug "$(PLUGIN_SLUG)" -MainFile "$(MAIN_FILE)" -OutputDir "$(OUTPUT_DIR)"

lint: ## Run php -l against distributable plugin PHP files.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -Command "$$php = Get-Command php -ErrorAction SilentlyContinue; if (-not $$php) { throw 'PHP is not installed or is not on PATH.' }; $$files = @(); foreach ($$path in @('$(MAIN_FILE)', 'uninstall.php')) { if (Test-Path -LiteralPath $$path) { $$files += Get-Item -LiteralPath $$path } }; if (Test-Path -LiteralPath 'src') { $$files += Get-ChildItem -LiteralPath 'src' -Recurse -File -Filter '*.php' }; if (-not $$files) { Write-Host 'No PHP files found.'; exit 0 }; foreach ($$file in $$files) { & $$php.Source -l $$file.FullName; if ($$LASTEXITCODE -ne 0) { exit $$LASTEXITCODE } }"

check: lint ## Run all local checks.

version: ## Print the plugin version from the main plugin file.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -Command "$$content = Get-Content -LiteralPath '$(MAIN_FILE)' -Raw; $$match = [regex]::Match($$content, '(?m)^[\s\/*#@]*Version:\s*(.+)$$'); if (-not $$match.Success) { throw 'Could not determine plugin version from $(MAIN_FILE)' }; $$match.Groups[1].Value.Trim()"

dist: ## List generated build artifacts.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -Command "if (Test-Path -LiteralPath '$(OUTPUT_DIR)') { Get-ChildItem -LiteralPath '$(OUTPUT_DIR)' | Format-Table -AutoSize } else { Write-Host 'No $(OUTPUT_DIR) directory found.' }"

clean: ## Remove generated build artifacts.
	@$(POWERSHELL) -NoProfile -ExecutionPolicy Bypass -Command "if (Test-Path -LiteralPath '$(OUTPUT_DIR)') { Remove-Item -LiteralPath '$(OUTPUT_DIR)' -Recurse -Force; Write-Host 'Removed $(OUTPUT_DIR).' } else { Write-Host 'Nothing to clean.' }"
