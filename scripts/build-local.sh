#!/usr/bin/env bash
# build-local.sh
#
# Build a fresh DBDiff native binary using ONLY Podman — no local PHP required.
#
# Steps:
#   1. Build  dist/dbdiff.phar  inside a php:8.3-cli container
#      (box.phar is downloaded into the container; host vendor/ is untouched)
#   2. Build  packages/@dbdiff/cli-linux-x64/dbdiff  inside an Ubuntu container
#      using static-php-cli (SPC).  SPC downloads and build output are cached
#      in a named Podman volume (dbdiff-spc-cache) so:
#        First run  : ~30-60 min  (downloads PHP source ~200 MB, compiles)
#        Subsequent : ~3-5 min    (volume cache is reused)
#
#   The musl x64 binary gets the same binary (SPC always produces a fully
#   static executable regardless of host libc).
#
# Usage:
#   scripts/build-local.sh              # build PHAR + linux-x64 binary
#   scripts/build-local.sh --skip-phar  # reuse existing dist/dbdiff.phar
#   scripts/build-local.sh --test       # build then run scripts/test-release-podman.sh
#   CLEAN_CACHE=1 scripts/build-local.sh  # wipe SPC volume and rebuild from scratch
#
# Requirements: Podman (no Docker, no PHP, no Node needed)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$SCRIPT_DIR/.."
cd "$ROOT"

# ── Flags ─────────────────────────────────────────────────────────────────────
SKIP_PHAR=0
RUN_TESTS=0
CLEAN_CACHE="${CLEAN_CACHE:-0}"

for arg in "$@"; do
    case "$arg" in
        --skip-phar)   SKIP_PHAR=1 ;;
        --test)        RUN_TESTS=1 ;;
        --clean-cache) CLEAN_CACHE=1 ;;
        --help|-h)
            sed -n '/^# /s/^# \?//p' "$0" | head -25
            exit 0
            ;;
        *)
            echo "Unknown option: $arg"
            echo "Usage: $0 [--skip-phar] [--test] [--clean-cache]"
            exit 1
            ;;
    esac
done

# ── Container runtime ─────────────────────────────────────────────────────────
if command -v podman &>/dev/null; then
    CT=podman
elif command -v docker &>/dev/null; then
    CT=docker
else
    echo "Error: neither Podman nor Docker found in PATH." >&2
    exit 1
fi
echo "Container runtime: $CT"

# ── SPC configuration (must match CI release workflow) ───────────────────────
SPC_PHP_VERSION="8.3"
SPC_EXTENSIONS="phar,filter,pdo_mysql,pdo_pgsql,pdo_sqlite,openssl,zlib,mbstring,dom,libxml,tokenizer,ctype,json,iconv"
SPC_LIBS="libiconv,libxml2,ncurses,libedit,postgresql,sqlite"

# Named volume — caches ~200 MB of PHP source downloads so subsequent builds
# only take 3-5 min instead of 30-60 min.
SPC_VOLUME="dbdiff-spc-cache"

if [ "$CLEAN_CACHE" = "1" ]; then
    echo "Wiping SPC cache volume ($SPC_VOLUME)..."
    $CT volume rm "$SPC_VOLUME" 2>/dev/null || true
fi

$CT volume create "$SPC_VOLUME" 2>/dev/null || true

mkdir -p dist "packages/@dbdiff/cli-linux-x64" "packages/@dbdiff/cli-linux-x64-musl"

# ── Step 1: Build PHAR ────────────────────────────────────────────────────────
if [ "$SKIP_PHAR" = "1" ]; then
    if [ ! -f "dist/dbdiff.phar" ]; then
        echo "Error: --skip-phar set but dist/dbdiff.phar does not exist." >&2
        exit 1
    fi
    echo "Skipping PHAR build — reusing existing $(du -sh dist/dbdiff.phar | cut -f1) dist/dbdiff.phar"
else
    echo ""
    echo "=== Step 1: Build PHAR ==="
    echo "    (downloads box.phar into container; host vendor/ is read-only)"

    $CT run --rm \
        -v "$ROOT:/app:ro,Z" \
        -v "$ROOT/dist:/app/dist:Z" \
        php:8.3-cli \
        sh -exc '
            apt-get update -qq
            apt-get install -y -q --no-install-recommends unzip git

            # Install Composer
            curl -sSL https://getcomposer.org/installer \
                | php -- --install-dir=/usr/local/bin --filename=composer
            composer --version

            # Download box.phar
            curl -sSL \
                https://github.com/box-project/box/releases/latest/download/box.phar \
                -o /usr/local/bin/box
            chmod +x /usr/local/bin/box
            box --version

            # Build from a temp copy with ONLY production deps so that
            # dev-only packages (phpdocumentor, webmozart/assert) do not inject
            # spurious ext-* requirements into the Box requirements checker.
            BUILD=$(mktemp -d)
            cp -a /app/src /app/dbdiff.php /app/box.json \
                  /app/composer.json /app/composer.lock /app/.git "$BUILD/"
            cd "$BUILD"
            composer install --no-dev --optimize-autoloader

            # box.json: output = dist/dbdiff.phar (relative to cwd)
            # We write directly to the mounted /app/dist.
            mkdir -p /app/dist
            box compile
        '

    echo "PHAR built: $(du -sh dist/dbdiff.phar | cut -f1)  dist/dbdiff.phar"
fi

# ── Step 2: Build static linux-x64 binary via SPC ────────────────────────────
echo ""
echo "=== Step 2: Build static linux-x64 binary ==="
echo "    SPC volume cache: $SPC_VOLUME"
echo "    First run takes 30-60 min (PHP compilation)."
echo "    Subsequent runs: ~3-5 min (sources cached)."

$CT run --rm \
    -v "$ROOT:/work:Z" \
    -v "${SPC_VOLUME}:/spc:Z" \
    -e "SPC_EXTENSIONS=$SPC_EXTENSIONS" \
    -e "SPC_LIBS=$SPC_LIBS" \
    -e "SPC_PHP_VERSION=$SPC_PHP_VERSION" \
    ubuntu:22.04 \
    bash -exc '
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        apt-get install -y -qq --no-install-recommends \
            curl ca-certificates build-essential pkg-config cmake \
            automake autoconf libtool re2c bison flex unzip git xz-utils sudo

        # Download the SPC binary (tiny ~5 MB; always latest)
        SPC_URL="https://github.com/crazywhalecc/static-php-cli/releases/latest/download/spc-linux-x86_64.tar.gz"
        curl -fsSL "$SPC_URL" | tar xz -C /usr/local/bin
        chmod +x /usr/local/bin/spc
        spc --version

        # Restore any previously-cached build artifacts into the SPC workdir
        mkdir -p /build
        if [ -d /spc/buildroot ]; then
            echo "Restoring cached buildroot from volume..."
            cp -a /spc/buildroot /build/buildroot
        fi
        if [ -d /spc/downloads ]; then
            echo "Restoring cached source downloads from volume..."
            cp -a /spc/downloads /build/downloads
        fi

        cd /build
        spc doctor --auto-fix

        # Download sources — SPC uses its own lock file to skip already-cached
        # items, so this is safe to call even when partially cached.
        if [ ! -f downloads/.lock.json ] || ! grep -q '"php-src"' downloads/.lock.json 2>/dev/null; then
            echo "Downloading PHP sources (~200 MB)..."
            spc download "php-src,micro,$SPC_EXTENSIONS,$SPC_LIBS" \
                --with-php="$SPC_PHP_VERSION"
        else
            echo "PHP sources already in cache — skipping download."
        fi

        spc build "$SPC_EXTENSIONS" --build-micro

        # Concatenate micro SAPI + PHAR → self-contained binary
        cat buildroot/bin/micro.sfx /work/dist/dbdiff.phar \
            > /work/packages/@dbdiff/cli-linux-x64/dbdiff
        chmod +x /work/packages/@dbdiff/cli-linux-x64/dbdiff

        # Persist build artifacts to the named volume for next time
        echo "Saving build artifacts to cache volume..."
        rm -rf /spc/buildroot /spc/downloads
        cp -a /build/buildroot /spc/buildroot
        cp -a /build/downloads /spc/downloads

        echo "Verifying binary..."
        /work/packages/@dbdiff/cli-linux-x64/dbdiff --version
    '

echo "Binary built: $(du -sh packages/@dbdiff/cli-linux-x64/dbdiff | cut -f1)  packages/@dbdiff/cli-linux-x64/dbdiff"

# ── Step 3: Copy to musl package (same static binary works on both glibc + musl)
echo ""
echo "=== Step 3: Copy to musl package ==="
cp packages/@dbdiff/cli-linux-x64/dbdiff packages/@dbdiff/cli-linux-x64-musl/dbdiff
echo "Copied: packages/@dbdiff/cli-linux-x64-musl/dbdiff"

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo "==================================================================="
echo " Build complete"
echo "==================================================================="
echo "  dist/dbdiff.phar                              $(du -sh dist/dbdiff.phar | cut -f1)"
echo "  packages/@dbdiff/cli-linux-x64/dbdiff         $(du -sh packages/@dbdiff/cli-linux-x64/dbdiff | cut -f1)"
echo "  packages/@dbdiff/cli-linux-x64-musl/dbdiff    $(du -sh packages/@dbdiff/cli-linux-x64-musl/dbdiff | cut -f1)"
echo ""
echo "Next steps:"
echo "  Test the binary:   scripts/test-release-podman.sh"
echo "  Test with a DB:    ENABLE_DB_TESTS=1 scripts/test-release-podman.sh"
echo ""

# ── Optional: run tests immediately ──────────────────────────────────────────
if [ "$RUN_TESTS" = "1" ]; then
    echo "Running binary tests..."
    ENABLE_DB_TESTS=1 "$SCRIPT_DIR/test-release-podman.sh"
fi
