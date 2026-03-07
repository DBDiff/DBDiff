#!/usr/bin/env bash
# release-binaries.sh
#
# One-off local script to build all platform binaries for any given DBDiff
# version and populate the packages/@dbdiff/ directories ready to publish.
#
# This is most useful for producing the v2.0.0 binaries retrospectively, or
# for building/testing locally before triggering the GitHub Actions workflow.
#
# Requirements:
#   - Podman or Docker in PATH
#   - PHP ≥ 8.0 + Composer installed locally (for the PHAR build step)
#   - humbug/box installed as a dev dependency (composer require --dev humbug/box)
#   - Internet access (SPC downloads PHP source tarballs ~200 MB each)
#
# Usage:
#   scripts/release-binaries.sh <version>
#
#   # Build binaries for v2.0.0 from the already-tagged v2.0.0 commit
#   scripts/release-binaries.sh 2.0.0
#
#   # Skip the PHAR build and use an existing one
#   SKIP_PHAR=1 scripts/release-binaries.sh 2.0.0
#
# Outputs:
#   dist/dbdiff.phar                                        ← PHAR
#   packages/@dbdiff/cli-linux-x64/dbdiff                  ← glibc x64 binary
#   packages/@dbdiff/cli-linux-x64-musl/dbdiff             ← musl x64 binary
#   packages/@dbdiff/cli-linux-arm64/dbdiff                 ← glibc arm64 binary (QEMU if needed)
#   packages/@dbdiff/cli-linux-arm64-musl/dbdiff            ← musl arm64 binary  (QEMU if needed)
#
# Note: macOS and Windows binaries must be built on their native runners
# (see the GitHub Actions release workflow).  This script covers all Linux
# targets using Podman/Docker on your current Linux machine.

set -euo pipefail

VERSION="${1:-}"

if [ -z "$VERSION" ]; then
    echo "Usage: $0 <version>  (e.g.  $0 2.0.0)"
    exit 1
fi

SKIP_PHAR="${SKIP_PHAR:-0}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$SCRIPT_DIR/.."
cd "$ROOT"

# ── Container runtime: prefer Podman, fall back to Docker ────────────────────
if command -v podman &>/dev/null; then
    CT=podman
elif command -v docker &>/dev/null; then
    CT=docker
else
    echo "Error: neither Podman nor Docker found in PATH."
    exit 1
fi
echo "Using container runtime: $CT"

# ── SPC extension / library lists (must match the CI workflow) ──────────────
# Extensions embedded into the static PHP binary
SPC_EXTENSIONS="phar,pdo_mysql,pdo_pgsql,pdo_sqlite,openssl,zlib,mbstring,dom,libxml,tokenizer,ctype,json,iconv"
# Native library sources SPC must download alongside the extensions
SPC_LIBS="libiconv,libxml2,ncurses,libedit,postgresql,sqlite"
SPC_PHP_VERSION="8.3"

export SPC_EXTENSIONS SPC_PHP_VERSION

# ── Step 1: Build the PHAR (if not skipped) ───────────────────────────────────
if [ "$SKIP_PHAR" = "1" ]; then
    if [ ! -f "dist/dbdiff.phar" ]; then
        echo "Error: SKIP_PHAR=1 but dist/dbdiff.phar does not exist."
        exit 1
    fi
    echo "Skipping PHAR build — using existing dist/dbdiff.phar"
else
    echo ""
    echo "=== Step 1: Building PHAR with Box ==="

    if [ ! -f "vendor/bin/box" ]; then
        echo "Installing humbug/box..."
        composer require --dev humbug/box --no-interaction
    fi

    mkdir -p dist
    vendor/bin/box compile

    if [ ! -f "dist/dbdiff.phar" ]; then
        echo "Error: PHAR build failed."
        exit 1
    fi

    PHAR_SIZE=$(du -sh dist/dbdiff.phar | cut -f1)
    echo "PHAR built: dist/dbdiff.phar (${PHAR_SIZE})"
fi

PHAR_ABS="$(cd dist && pwd)/dbdiff.phar"

# ── Step 2: Linux glibc x64 ───────────────────────────────────────────────────
echo ""
echo "=== Step 2: Building Linux x64 glibc binary ==="

$CT run --rm \
    -v "$ROOT:/work:Z" \
    -e SPC_EXTENSIONS \
    -e SPC_LIBS \
    -e SPC_PHP_VERSION \
    ubuntu:22.04 \
    bash -exc '
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        apt-get install -y -qq --no-install-recommends \
            curl ca-certificates sudo build-essential pkg-config cmake \
            automake autoconf libtool re2c bison flex unzip git xz-utils
        echo "root ALL=(ALL) NOPASSWD: ALL" >> /etc/sudoers
        curl -fsSL \
            "https://github.com/crazywhalecc/static-php-cli/releases/latest/download/spc-linux-x86_64.tar.gz" \
            | tar xz
        chmod +x spc && mv spc /usr/local/bin/spc
        mkdir -p /build && cd /build
        spc doctor --auto-fix
        spc download "php-src,micro,$SPC_EXTENSIONS,$SPC_LIBS" --with-php="$SPC_PHP_VERSION"
        spc build "$SPC_EXTENSIONS" --build-micro
        cat buildroot/bin/micro.sfx /work/dist/dbdiff.phar > /work/packages/@dbdiff/cli-linux-x64/dbdiff
        chmod +x /work/packages/@dbdiff/cli-linux-x64/dbdiff
        /work/packages/@dbdiff/cli-linux-x64/dbdiff --version
    '

echo "Built: packages/@dbdiff/cli-linux-x64/dbdiff"

# ── Step 3: Linux musl x64 ───────────────────────────────────────────────────
# SPC always cross-compiles with its own musl toolchain (x86_64-linux-musl-gcc),
# so the binary produced in Step 2 is already fully static (ldd: "not a dynamic
# executable"). The musl npm package gets the same binary file.
echo ""
echo "=== Step 3: Linux x64 musl binary (copy from glibc build — already static) ==="
cp packages/@dbdiff/cli-linux-x64/dbdiff packages/@dbdiff/cli-linux-x64-musl/dbdiff
echo "Built: packages/@dbdiff/cli-linux-x64-musl/dbdiff"


# ── Step 4: Linux glibc arm64 ─────────────────────────────────────────────────
echo ""
echo "=== Step 4: Building Linux arm64 glibc binary ==="
echo "    (This uses QEMU emulation if you are on x64 — expect it to be slow)"

$CT run --rm \
    --platform linux/arm64 \
    -v "$ROOT:/work:Z" \
    -e SPC_EXTENSIONS \
    -e SPC_LIBS \
    -e SPC_PHP_VERSION \
    ubuntu:22.04 \
    bash -exc '
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        apt-get install -y -qq --no-install-recommends \
            curl ca-certificates sudo build-essential pkg-config cmake \
            automake autoconf libtool re2c bison flex unzip git xz-utils
        echo "root ALL=(ALL) NOPASSWD: ALL" >> /etc/sudoers
        curl -fsSL \
            "https://github.com/crazywhalecc/static-php-cli/releases/latest/download/spc-linux-aarch64.tar.gz" \
            | tar xz
        chmod +x spc && mv spc /usr/local/bin/spc
        mkdir -p /build && cd /build
        spc doctor --auto-fix
        spc download "php-src,micro,$SPC_EXTENSIONS,$SPC_LIBS" --with-php="$SPC_PHP_VERSION"
        spc build "$SPC_EXTENSIONS" --build-micro
        cat buildroot/bin/micro.sfx /work/dist/dbdiff.phar > /work/packages/@dbdiff/cli-linux-arm64/dbdiff
        chmod +x /work/packages/@dbdiff/cli-linux-arm64/dbdiff
    '

echo "Built: packages/@dbdiff/cli-linux-arm64/dbdiff"

# ── Step 5: Linux musl arm64 ─────────────────────────────────────────────────
# Same reasoning as Step 3: SPC produces a statically-linked binary regardless
# of whether the host is glibc or musl, so we just copy from Step 4.
echo ""
echo "=== Step 5: Linux arm64 musl binary (copy from glibc build — already static) ==="
cp packages/@dbdiff/cli-linux-arm64/dbdiff packages/@dbdiff/cli-linux-arm64-musl/dbdiff

echo "Built: packages/@dbdiff/cli-linux-arm64-musl/dbdiff"

# ── Step 6: Stamp version into package.json files ────────────────────────────
echo ""
echo "=== Step 6: Stamping version $VERSION into package.json files ==="

for pkg_dir in packages/@dbdiff/cli packages/@dbdiff/cli-*/; do
    node -e "
        const fs = require('fs');
        const p = '${pkg_dir}package.json';
        const j = JSON.parse(fs.readFileSync(p, 'utf8'));
        j.version = '${VERSION}';
        if (j.optionalDependencies) {
            for (const k of Object.keys(j.optionalDependencies)) {
                j.optionalDependencies[k] = '${VERSION}';
            }
        }
        fs.writeFileSync(p, JSON.stringify(j, null, 2) + '\n');
        console.log('Updated', p);
    "
done

# ── Summary ────────────────────────────────────────────────────────────────────
echo ""
echo "==================================================================="
echo " All Linux binaries built for v${VERSION}"
echo "==================================================================="
echo ""
echo " PHAR:"
ls -lh dist/dbdiff.phar
echo ""
echo " Linux binaries:"
ls -lh \
    packages/@dbdiff/cli-linux-x64/dbdiff \
    packages/@dbdiff/cli-linux-x64-musl/dbdiff \
    packages/@dbdiff/cli-linux-arm64/dbdiff \
    packages/@dbdiff/cli-linux-arm64-musl/dbdiff
echo ""
echo " Next steps:"
echo "   1. Run the Podman release tests:"
echo "      scripts/test-release-podman.sh"
echo ""
echo "   2. Publish the Linux packages to npm:"
echo "      for pkg in packages/@dbdiff/cli-linux-*/; do"
echo "          npm publish \"\$pkg\" --access public"
echo "      done"
echo "      npm publish packages/@dbdiff/cli --access public"
echo ""
echo "   3. For macOS and Windows binaries, trigger the GitHub Actions"
echo "      release workflow — or build on native macOS/Windows machines."
echo ""
