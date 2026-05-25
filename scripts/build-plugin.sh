#!/usr/bin/env bash
# build-plugin.sh — stage plugin files and package them into a distributable ZIP
set -euo pipefail

PLUGIN_SLUG="${1:-wp-hosting-benchmark}"
MAIN_FILE="${2:-wp-hosting-benchmark.php}"
OUTPUT_DIR="${3:-dist}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN_FILE_PATH="$REPO_ROOT/$MAIN_FILE"

if [[ ! -f "$MAIN_FILE_PATH" ]]; then
	echo "Main plugin file not found: $MAIN_FILE_PATH" >&2
	exit 1
fi

VERSION=$(grep -m1 'Version:' "$MAIN_FILE_PATH" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

if [[ -z "$VERSION" ]]; then
	echo "Could not determine plugin version from $MAIN_FILE" >&2
	exit 1
fi

command -v zip >/dev/null 2>&1 || { echo "zip is not installed or is not on PATH." >&2; exit 1; }

OUTPUT_ROOT="$REPO_ROOT/$OUTPUT_DIR"
STAGE_ROOT="$OUTPUT_ROOT/$PLUGIN_SLUG"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="$OUTPUT_ROOT/$ZIP_NAME"

INCLUDE_PATHS=("$MAIN_FILE" "readme.txt" "uninstall.php" "src" "languages")

# Clean and recreate output dir
rm -rf "$OUTPUT_ROOT"
mkdir -p "$STAGE_ROOT"

for entry in "${INCLUDE_PATHS[@]}"; do
	SOURCE_PATH="$REPO_ROOT/$entry"
	[[ -e "$SOURCE_PATH" ]] || continue
	DEST_PATH="$STAGE_ROOT/$entry"
	if [[ -d "$SOURCE_PATH" ]]; then
		cp -r "$SOURCE_PATH" "$DEST_PATH"
	else
		cp "$SOURCE_PATH" "$DEST_PATH"
	fi
done

# Create ZIP with paths relative to OUTPUT_ROOT so archive entries are <slug>/...
(cd "$OUTPUT_ROOT" && zip -r "$ZIP_NAME" "$PLUGIN_SLUG/")

echo "Built: $ZIP_PATH"
