#!/usr/bin/env bash
set -euo pipefail

# Make `spc doctor --auto-fix` survive musl.libc.org being unreachable.
#
# spc's fix-musl-wrapper fetches musl source from https://musl.libc.org — a
# single lightly-provisioned origin with no CDN. When it is down, every Linux
# leg fails after a ~135s connect timeout; the retry loop added in #185 helps
# with a blip but cannot help with an outage, because each attempt hits the
# same dead host. This has now cost us a release (#185) and a PR run.
#
# Two layers, in the order the build should prefer them:
#
#   1. RESTORE — if a previous run's musl install was cached, put it back.
#      spc's checkMusl()/checkMuslCrossMake() are pure file-existence tests, so
#      a restored install makes doctor a no-op and nothing is downloaded.
#
#   2. PRE-SEED — otherwise fetch the tarball ourselves (origin first, then
#      mirrors) and register it in spc's lock file, so spc's own fixMusl()
#      finds it already downloaded and skips the network.
#
# Pre-seeding rather than installing musl ourselves is deliberate: spc applies
# the CVE-2025-26519 patches after unpacking, and hand-rolling the install would
# silently drop them. We supply the bytes; spc still patches and installs.
#
# The download is verified against a pinned SHA-256 and fails closed on
# mismatch. A mirror is only ever a byte source, never a trust anchor — a
# compromised mirror must not be able to substitute a backdoored libc.
#
# Usage:  prepare-musl.sh <cache-dir>
#   <cache-dir> is a workspace path that actions/cache saves and restores.

CACHE_DIR="${1:-musl-cache}"

# Pinned to the version hardcoded in spc's LinuxMuslCheck::fixMusl(). If spc
# bumps it, this stops matching and we simply fall through to spc's own
# download — degraded to today's behaviour, never broken.
MUSL_VERSION="musl-1.2.5"
MUSL_SHA256="a9a118bbe84d8764da0ea0d28b3ab3fae8477fc7e4085d90102b8596fc7c75e4"

# Origin first: it is the canonical source and usually works. The mirrors are
# distribution archives that were verified byte-identical to the origin tarball
# and to each other at the time of writing.
MUSL_URLS=(
  "https://musl.libc.org/releases/${MUSL_VERSION}.tar.gz"
  "https://distfiles.macports.org/musl/${MUSL_VERSION}.tar.gz"
  "https://sources.buildroot.net/musl/${MUSL_VERSION}.tar.gz"
)

ARCH="$(uname -m)"
WRAPPER="/lib/ld-musl-${ARCH}.so.1"
MUSL_PREFIX="/usr/local/musl"

log() { printf '  [musl] %s\n' "$*"; }

# ── 1. restore a cached install ──────────────────────────────────────────────
# checkMusl() wants the wrapper AND ${MUSL_PREFIX}/lib/libc.a; checkMuslCrossMake()
# wants the cross toolchain under the same prefix. Restoring the whole prefix
# plus the wrapper satisfies both, so doctor finds nothing to fix.
if [ -f "$CACHE_DIR/musl-prefix.tar.gz" ]; then
  log "restoring cached musl toolchain"
  sudo tar -xzf "$CACHE_DIR/musl-prefix.tar.gz" -C / || true

  if [ -f "${MUSL_PREFIX}/lib/libc.a" ] && [ -f "$WRAPPER" ]; then
    log "restored — doctor will skip fix-musl-wrapper, no download needed"
    exit 0
  fi
  log "cache incomplete, falling through to pre-seed"
fi

# ── 2. pre-seed spc's download directory ─────────────────────────────────────
mkdir -p downloads
TARBALL="downloads/${MUSL_VERSION}.tar.gz"

verify() {
  local actual
  actual="$(sha256sum "$1" | cut -d' ' -f1)"
  [ "$actual" = "$MUSL_SHA256" ] || { log "checksum MISMATCH (got $actual)"; return 1; }
}

if [ -f "$TARBALL" ] && verify "$TARBALL" 2>/dev/null; then
  log "tarball already present and verified"
else
  fetched=false
  for url in "${MUSL_URLS[@]}"; do
    log "fetching ${url}"
    # Short connect timeout: a dead origin should cost seconds, not the ~135s
    # the default takes, so we reach the mirrors quickly.
    if curl -fsSL --connect-timeout 20 --max-time 300 -o "${TARBALL}.part" "$url" 2>/dev/null; then
      if verify "${TARBALL}.part"; then
        mv -f "${TARBALL}.part" "$TARBALL"
        log "verified sha256 ${MUSL_SHA256}"
        fetched=true
        break
      fi
      # Wrong bytes from a reachable host is a supply-chain signal, not a
      # transient error: refuse this source outright rather than retrying it.
      log "REFUSING ${url} — content did not match the pinned checksum"
    else
      log "unreachable, trying next source"
    fi
    rm -f "${TARBALL}.part"
  done

  if [ "$fetched" != true ]; then
    # Not fatal: spc will attempt its own download and may succeed. Failing here
    # would turn a soft degradation into a hard one.
    log "WARNING: could not obtain a verified ${MUSL_VERSION}.tar.gz from any source"
    log "         leaving spc to try its own download"
    exit 0
  fi
fi

# Tell spc the source is already downloaded. Its isAlreadyDownloaded() checks
# the lock entry and that the file exists; without the entry it re-downloads
# regardless of the file being there.
python3 - "$MUSL_VERSION" <<'PY' || log "could not update lock file; spc will download normally"
import json, os, sys
name = sys.argv[1]
path = os.path.join('downloads', '.lock.json')
data = {}
if os.path.exists(path):
    try:
        with open(path) as fh:
            data = json.load(fh)
    except (ValueError, OSError):
        data = {}
data[name] = {
    'source_type': 'archive',   # SPC_SOURCE_ARCHIVE
    'filename': f'{name}.tar.gz',
    'move_path': None,
    'lock_as': 1,               # SPC_DOWNLOAD_SOURCE
}
with open(path, 'w') as fh:
    json.dump(data, fh, indent=4)
print(f'  [musl] registered {name} in downloads/.lock.json')
PY
