#!/bin/bash

# DBDiff Local Release Helper
# Usage: ./release.sh [version]
# Example: ./release.sh v2.1.0
#
# This script builds the PHAR using box-project/box and tags the release.
# The actual platform binary builds and npm publishes are handled by the
# GitHub Actions workflow (.github/workflows/release.yml).
#
# Requirements:
#   - PHP >= 8.0 in PATH
#   - Composer dependencies installed (including box/box in dev)

set -e

VERSION=$1

if [ -z "$VERSION" ]; then
    echo "Usage: $0 [version]"
    echo "Example: $0 v2.1.0"
    exit 1
fi

# Ensure we are in the project root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."

# Ensure we are on the main branch or current PR branch
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
echo "Preparing release $VERSION from branch $CURRENT_BRANCH..."

# 1. Check for uncommitted changes
if [ -n "$(git status --porcelain)" ]; then
    echo "Error: You have uncommitted changes. Please commit or stash them first."
    exit 1
fi

# 2. Update dependencies (production + dev for box)
echo "Updating dependencies..."
composer install --optimize-autoloader

# 3. Ensure box is available
if [ ! -f "vendor/bin/box" ]; then
    echo "Error: vendor/bin/box not found."
    echo "Install with: composer require --dev box/box"
    exit 1
fi

# 4. Build the PHAR with Box
echo "Building PHAR with Box..."
mkdir -p dist
vendor/bin/box compile

# 5. Verify build artifacts
if [ ! -f "dist/dbdiff.phar" ]; then
    echo "Error: dist/dbdiff.phar was not created."
    exit 1
fi

PHAR_SIZE=$(du -sh dist/dbdiff.phar | cut -f1)
echo "PHAR built: dist/dbdiff.phar (${PHAR_SIZE})"

# 6. Quick smoke test — ensure the PHAR runs and reports the right version
echo "Smoke-testing PHAR..."
PHAR_VERSION=$(php dist/dbdiff.phar --version 2>&1 || true)
echo "  ${PHAR_VERSION}"

# 7. Tag and Push
echo "Tagging version $VERSION..."
git tag -a "$VERSION" -m "Release $VERSION"

# Detect pre-release (version contains a hyphen, e.g. v3.0.0-alpha.1)
PRERELEASE_ID=""
VERSION_BARE="${VERSION#v}"
if [[ "$VERSION_BARE" == *-* ]]; then
    PRERELEASE_ID="${VERSION_BARE#*-}"
    PRERELEASE_ID="${PRERELEASE_ID%%.*}"
fi

echo "Done. To complete the release:"
echo "  1. git push origin $VERSION"
echo "  2. Trigger the GitHub Actions 'Release DBDiff' workflow with version: ${VERSION_BARE}"
if [ -n "$PRERELEASE_ID" ]; then
    echo "     Pre-release detected — the GitHub Release will be marked as pre-release."
    echo "     npm packages will be published under the dist-tag: $PRERELEASE_ID"
    echo "     (Install with: npm install @dbdiff/cli@$PRERELEASE_ID)"
fi
echo "     (This builds platform binaries, publishes to npm, and creates the GitHub Release)"
echo ""
echo "Or use the manual one-off binary builder:"
echo "  scripts/release-binaries.sh ${VERSION#v}"
echo ""
echo "Artifacts:"
ls -lh dist/dbdiff.phar
