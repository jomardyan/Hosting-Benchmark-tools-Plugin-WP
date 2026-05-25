#!/usr/bin/env bash
# bump-version.sh — update the plugin version in the main file and readme.txt
set -euo pipefail

MAIN_FILE="${1:-wp-hosting-benchmark.php}"
README_FILE="${2:-readme.txt}"
VERSION_PART="${3:-patch}"
NEW_VERSION="${4:-}"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN_FILE_PATH="$REPO_ROOT/$MAIN_FILE"
README_FILE_PATH="$REPO_ROOT/$README_FILE"

if [[ ! -f "$MAIN_FILE_PATH" ]]; then
	echo "Main plugin file not found: $MAIN_FILE_PATH" >&2
	exit 1
fi

CURRENT_VERSION=$(grep -m1 'Version:' "$MAIN_FILE_PATH" | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')

if [[ -z "$CURRENT_VERSION" ]]; then
	echo "Could not determine plugin version from $MAIN_FILE" >&2
	exit 1
fi

if [[ -z "$NEW_VERSION" ]]; then
	IFS='.' read -ra PARTS <<< "$CURRENT_VERSION"
	MAJOR="${PARTS[0]}"
	MINOR="${PARTS[1]}"
	PATCH="${PARTS[2]}"

	case "$VERSION_PART" in
		major) MAJOR=$((MAJOR + 1)); MINOR=0; PATCH=0 ;;
		minor) MINOR=$((MINOR + 1)); PATCH=0 ;;
		patch) PATCH=$((PATCH + 1)) ;;
		*) echo "Invalid VERSION_PART: $VERSION_PART (use major, minor, or patch)" >&2; exit 1 ;;
	esac

	NEW_VERSION="$MAJOR.$MINOR.$PATCH"
fi

if ! [[ "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Version must use semantic version format: MAJOR.MINOR.PATCH" >&2
	exit 1
fi

# Update `* Version: x.y.z` in the plugin header
sed -i -E \
	"s/^([[:space:]]*\*[[:space:]]*Version:[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+([[:space:]]*)$/\1$NEW_VERSION\2/" \
	"$MAIN_FILE_PATH"

# Update the WP_HOSTING_BENCHMARK_VERSION constant
sed -i -E \
	"s/^(define\([[:space:]]*'WP_HOSTING_BENCHMARK_VERSION'[[:space:]]*,[[:space:]]*')[0-9]+\.[0-9]+\.[0-9]+('[[:space:]]*\);)/\1$NEW_VERSION\2/" \
	"$MAIN_FILE_PATH"

# Update Stable tag in readme.txt if it exists
if [[ -f "$README_FILE_PATH" ]]; then
	sed -i -E \
		"s/^(Stable tag:[[:space:]]*)[0-9]+\.[0-9]+\.[0-9]+([[:space:]]*)$/\1$NEW_VERSION\2/" \
		"$README_FILE_PATH"
fi

echo "Version bumped: $CURRENT_VERSION -> $NEW_VERSION"
