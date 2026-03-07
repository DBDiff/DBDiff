#!/usr/bin/env bash
# update-homebrew-formula.sh
#
# Downloads the platform binaries for a given GitHub release, computes their
# SHA256 hashes, and patches them into the homebrew-dbdiff Formula/dbdiff.rb.
#
# Run this AFTER the GitHub release is published (so the assets are available).
#
# Usage:
#   scripts/update-homebrew-formula.sh <version> <path/to/homebrew-dbdiff>
#
#   # Example:
#   scripts/update-homebrew-formula.sh 2.0.0 ../homebrew-dbdiff
#
# Requirements:
#   - curl
#   - sha256sum (Linux) or shasum -a 256 (macOS)

set -euo pipefail

VERSION="${1:-}"
FORMULA_REPO="${2:-}"

if [ -z "$VERSION" ] || [ -z "$FORMULA_REPO" ]; then
    echo "Usage: $0 <version> <path/to/homebrew-dbdiff>"
    echo "  e.g. $0 2.0.0 ../homebrew-dbdiff"
    exit 1
fi

FORMULA="$FORMULA_REPO/Formula/dbdiff.rb"

if [ ! -f "$FORMULA" ]; then
    echo "Error: Formula not found at $FORMULA"
    exit 1
fi

BASE_URL="https://github.com/DBDiff/DBDiff/releases/download/v${VERSION}"

ASSETS=(
    "dbdiff-darwin-arm64"
    "dbdiff-darwin-x64"
    "dbdiff-linux-arm64"
    "dbdiff-linux-x64"
)

# Use sha256sum on Linux, shasum on macOS
if command -v sha256sum &>/dev/null; then
    SHA_CMD="sha256sum"
else
    SHA_CMD="shasum -a 256"
fi

echo "Computing SHA256 for v${VERSION} release assets..."
echo ""

declare -A HASHES

for asset in "${ASSETS[@]}"; do
    url="${BASE_URL}/${asset}"
    echo -n "  ${asset}: "
    hash=$(curl -fsSL "$url" | $SHA_CMD | awk '{print $1}')
    HASHES[$asset]="$hash"
    echo "$hash"
done

echo ""
echo "Patching $FORMULA ..."

# The anchor comment '# bumped by update-homebrew-formula.sh' on each sha256
# line makes them uniquely identifiable regardless of whether they currently
# hold a PLACEHOLDER_* string or a real 64-char hex hash from a previous run.
# Python replaces them in document order: darwin-arm64, darwin-x64,
# linux-arm64, linux-x64 — matching the order in the formula's on_macos /
# on_linux blocks.
python3 - "$FORMULA" "${VERSION}" \
  "${HASHES[dbdiff-darwin-arm64]}" \
  "${HASHES[dbdiff-darwin-x64]}" \
  "${HASHES[dbdiff-linux-arm64]}" \
  "${HASHES[dbdiff-linux-x64]}" <<'PYEOF'
import sys, re

formula_path, version, *hashes = sys.argv[1:]
# Each sha256 line in the formula carries this anchor comment so we can
# reliably find and replace it regardless of its current value.
anchor = '# bumped by update-homebrew-formula.sh'

with open(formula_path) as f:
    content = f.read()

# Stamp version
content = re.sub(r'^  version ".*"', f'  version "{version}"', content, flags=re.MULTILINE)

# Stamp SHA256 entries in document order (preserves leading whitespace)
counter = [0]
def stamp(m):
    leading = m.group(1)
    h = hashes[counter[0]]
    counter[0] += 1
    return f'{leading}sha256 "{h}" {anchor}'

new_content = re.sub(
    r'( +)sha256 "[^"]*" ' + re.escape(anchor),
    stamp,
    content
)

if counter[0] != 4:
    print(f"ERROR: expected 4 sha256 anchors, found {counter[0]}", file=sys.stderr)
    sys.exit(1)

with open(formula_path, 'w') as f:
    f.write(new_content)

print(f"  Stamped version {version} and {counter[0]} sha256 entries")
PYEOF

echo ""
echo "Updated $FORMULA"
echo ""
echo "Next steps:"
echo "  cd $FORMULA_REPO"
echo "  git add Formula/dbdiff.rb"
echo "  git commit -m \"dbdiff v${VERSION}\""
echo "  git push"
