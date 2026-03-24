#!/usr/bin/env bash
# encode-password.sh — percent-encode a database password for safe use in a DSN URL.
#
# Encodes all characters except RFC 3986 unreserved characters (A-Z a-z 0-9 - _ . ~).
# UTF-8 passwords are handled correctly: multi-byte characters are encoded byte-by-byte.
#
# Usage:
#   scripts/encode-password.sh 'my$ecret#pass'
#   echo 'my$ecret#pass' | scripts/encode-password.sh
#
# Capture for use in another command:
#   PASS=$(scripts/encode-password.sh 'my$ecret#pass')
#   dbdiff diff --server1-url="postgres://user:${PASS}@host:5432/db" ...
#
# If dbdiff is already installed, prefer the built-in command instead:
#   PASS=$(dbdiff url:encode 'my$ecret#pass')

set -euo pipefail

# ── Read input ────────────────────────────────────────────────────────────────

raw="${1:-}"

if [[ -z "$raw" && ! -t 0 ]]; then
    # No argument — try stdin (supports: echo 'pass' | encode-password.sh)
    IFS= read -r raw || true
fi

if [[ -z "${raw:-}" ]]; then
    printf 'Usage: %s <password>\n       echo <password> | %s\n' \
        "$(basename "$0")" "$(basename "$0")" >&2
    exit 1
fi

# ── Encode ────────────────────────────────────────────────────────────────────
#
# Force LC_ALL=C so bash treats the string as a byte array.
# This makes ${raw:i:1} return one byte at a time, which is exactly what we
# want for percent-encoding: each byte of a multi-byte UTF-8 character gets
# its own %HH escape (e.g. 'ä' = 0xC3 0xA4 → '%C3%A4').

LC_ALL=C

encoded=""
len="${#raw}"

for (( i = 0; i < len; i++ )); do
    c="${raw:i:1}"
    if [[ "$c" =~ ^[A-Za-z0-9._~-]$ ]]; then
        encoded+="$c"
    else
        printf -v hex '%02X' "'$c"
        encoded+="%${hex}"
    fi
done

printf '%s\n' "$encoded"
