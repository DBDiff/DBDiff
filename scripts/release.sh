#!/bin/bash

# DBDiff Local Release Helper
# Usage: ./release.sh [version]
# Example: ./release.sh v1.1.0

set -e

VERSION=$1

if [ -z "$VERSION" ]; then
    echo "Usage: $0 [version]"
    echo "Example: $0 v1.1.0"
    exit 1
fi

# Ensure we are in the project root
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/.."

# Ensure we are on the main branch or current PR branch
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
echo "🚀 Preparing release $VERSION from branch $CURRENT_BRANCH..."

# 1. Check for uncommitted changes
if [ -n "$(git status --porcelain)" ]; then
    echo "❌ Error: You have uncommitted changes. Please commit or stash them first."
    exit 1
fi

# 2. Update dependencies
echo "📦 Updating dependencies..."
composer install --no-dev --optimize-autoloader

# 3. Build the PHAR
echo "🔨 Building PHAR..."
if [ "$(php -r 'echo ini_get("phar.readonly");')" == "1" ]; then
    echo "⚠️  Phar readonly is ON. Attempting build with -dphar.readonly=0"
    php -dphar.readonly=0 scripts/build
else
    php scripts/build
fi

# 4. Verify build artifacts
if [ ! -f "dist/dbdiff.phar" ]; then
    echo "❌ Error: dist/dbdiff.phar was not created."
    exit 1
fi

# 5. Tag and Push
echo "🏷️  Tagging version $VERSION..."
git tag -a "$VERSION" -m "Release $VERSION"

echo "✅ Ready to release!"
echo "Next steps:"
echo "  1. git push origin $VERSION"
echo "  2. Go to https://github.com/DBDiff/DBDiff/releases and upload the files from the dist/ folder."
echo "  3. Packagist will update automatically once the tag is pushed."
echo ""
echo "Files ready in dist/:"
ls -lh dist/dbdiff.phar*
